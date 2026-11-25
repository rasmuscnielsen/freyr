<?php

namespace App\Services\Embeddings;

use App\Models\Posting;
use App\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;

final class EmbeddingGenerator
{
    /**
     * The runtime provider name the API key is injected into. Keeping it
     * separate from the shared `openai` provider avoids mutating config
     * other AI features may rely on.
     */
    private const PROVIDER = 'freyr_statements';

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
                provider: $this->provider(),
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
     * Inject the resolved API key into a dedicated provider instance and
     * return its name.
     */
    private function provider(): string
    {
        config()->set('ai.providers.'.self::PROVIDER, [
            'driver' => config('services.statement_embeddings.provider', 'openai'),
            'key' => $this->settings->embeddingApiKey(),
        ]);

        return self::PROVIDER;
    }
}
