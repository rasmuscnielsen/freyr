<?php

namespace App\Services\Embeddings;

use App\Models\Posting;
use App\Support\PlatformSettings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Laravel\Ai\Ai;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

final class EmbeddingGenerator
{
    /**
     * The runtime provider name the API key is injected into. Keeping it
     * separate from the shared `openai` provider avoids mutating config
     * other AI features may rely on.
     */
    private const PROVIDER = 'freyr_statements';

    private const KEY_CHECK_PROVIDER = 'freyr_key_check';

    private const NO_CREDIT = 'This OpenAI account has no credit. Add billing on platform.openai.com and try again.';

    private const UNREACHABLE = "Couldn't reach OpenAI to check the key. Try again in a moment.";

    /**
     * Bank descriptions such as "NETTO 4021 KØBENHAVN" are rarely more than
     * 20 tokens.
     */
    private const TYPICAL_TOKENS_PER_POSTING = 20;

    public function __construct(private PlatformSettings $settings) {}

    /**
     * @return positive-int
     */
    public function batchSize(): int
    {
        return max(1, (int) config('services.statement_embeddings.batch_size', 50));
    }

    public function dimensions(): int
    {
        return max(1, (int) config('services.statement_embeddings.dimensions', 1536));
    }

    /**
     * Embed postings that have no embedding yet. Returns 0 when similarity features are off or no
     * API key is configured — the platform works without them.
     */
    public function generateMissing(int $limit = 100, ?int $batchSize = null): int
    {
        if (! $this->settings->similarityAvailable()) {
            return 0;
        }

        $batchSize ??= $this->batchSize();

        $postings = $this->missingQuery()
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'description']);

        return $postings
            ->chunk($batchSize)
            ->sum(fn (Collection $chunk): int => $this->generateForChunk($chunk));
    }

    /**
     * Whether any postings are still waiting for an embedding.
     */
    public function hasMissing(): bool
    {
        return $this->missingQuery()->exists();
    }

    /**
     * A validation rule that fails with openAiKeyProblem()'s message, for the
     * forms that save a key.
     */
    public function workingOpenAiKeyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (($problem = $this->openAiKeyProblem(trim((string) $value))) !== null) {
                $fail($problem);
            }
        };
    }

    /**
     * Try an OpenAI key with a one-word embedding request before it is
     * saved. Returns what the user should do about it, or null when the key
     * works or embeddings use another provider (the key is then unused).
     */
    public function openAiKeyProblem(string $apiKey): ?string
    {
        if (config('services.statement_embeddings.provider', 'openai') !== 'openai') {
            return null;
        }

        try {
            Embeddings::for(['Freyr'])
                ->dimensions($this->dimensions())
                ->timeout(15)
                ->generate(
                    provider: $this->provider(self::KEY_CHECK_PROVIDER, 'openai', $apiKey),
                    model: config('services.statement_embeddings.model'),
                );
        } catch (InsufficientCreditsException) {
            return self::NO_CREDIT;
        } catch (RateLimitedException $e) {
            // OpenAI answers 429 both for a busy key, which works, and for
            // an account without credit.
            $code = $e->getPrevious() instanceof RequestException
                ? $e->getPrevious()->response->json('error.code')
                : null;

            return $code === 'insufficient_quota' ? self::NO_CREDIT : null;
        } catch (RequestException $e) {
            return match ($e->response->status()) {
                401 => "This key doesn't work. Check that you copied all of it.",
                403 => "This key can't create embeddings. Check its permissions on platform.openai.com.",
                default => self::UNREACHABLE,
            };
        } catch (Throwable) {
            return self::UNREACHABLE;
        }

        return null;
    }

    /**
     * A deliberately generous cost estimate in USD, using about three
     * characters per token (bank descriptions tokenize worse than prose):
     * what the postings still waiting for an embedding would cost, and a
     * reference price for 10,000 typical postings so the figure means
     * something before any postings exist.
     *
     * @return array{pendingPostings: int, pendingUsd: float, usdPer10k: float}
     */
    public function costEstimate(): array
    {
        $usdPerToken = (float) config('services.statement_embeddings.usd_per_million_tokens') / 1_000_000;

        $pending = $this->missingQuery()
            ->toBase()
            ->selectRaw('count(*) as postings, coalesce(sum(length(description)), 0) as characters')
            ->first();

        return [
            'pendingPostings' => (int) $pending->postings,
            'pendingUsd' => ceil((int) $pending->characters / 3) * $usdPerToken,
            'usdPer10k' => 10_000 * self::TYPICAL_TOKENS_PER_POSTING * $usdPerToken,
        ];
    }

    private function missingQuery(): Builder
    {
        return Posting::query()
            ->whereNull('description_embedded_at')
            ->where('description', '!=', '');
    }

    /**
     * @param  Collection<int, Posting>  $postings
     */
    private function generateForChunk(Collection $postings): int
    {
        if ($postings->isEmpty()) {
            return 0;
        }

        $response = Embeddings::for($postings->pluck('description')->all())
            ->dimensions($this->dimensions())
            ->cache()
            ->generate(
                provider: $this->provider(
                    self::PROVIDER,
                    config('services.statement_embeddings.provider', 'openai'),
                    $this->settings->embeddingApiKey(),
                ),
                model: config('services.statement_embeddings.model'),
            );

        $now = now();

        $postings->values()->each(function (Posting $posting, int $index) use ($response, $now): void {
            $posting->forceFill([
                'description_embedding' => $response->embeddings[$index],
                'description_embedded_at' => $now,
            ])->save();
        });

        return $postings->count();
    }

    /**
     * Inject the API key into a dedicated provider and return its name. The
     * AI manager caches provider instances for the life of the process, so
     * the cached one is dropped; otherwise a queue worker would keep using
     * a replaced key.
     */
    private function provider(string $name, string $driver, ?string $apiKey): string
    {
        config()->set('ai.providers.'.$name, [
            'driver' => $driver,
            'key' => $apiKey,
        ]);

        Ai::forgetInstance($name);

        return $name;
    }
}
