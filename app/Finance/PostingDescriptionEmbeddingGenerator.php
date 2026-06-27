<?php

namespace App\Finance;

use App\Models\Posting;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;

class PostingDescriptionEmbeddingGenerator
{
    /**
     * @return positive-int
     */
    public function batchSize(): int
    {
        return max(1, (int) config('services.statement_embeddings.batch_size', 50));
    }

    /**
     * @return positive-int
     */
    public function dimensions(): int
    {
        return max(1, (int) config('services.statement_embeddings.dimensions', 1536));
    }

    public function generateMissing(int $limit = 100, ?int $batchSize = null): int
    {
        $batchSize ??= $this->batchSize();

        $postings = Posting::query()
            ->whereNull('description_embedded_at')
            ->where('description', '!=', '')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'description']);

        return $postings
            ->chunk($batchSize)
            ->sum(fn (Collection $chunk): int => $this->generateForChunk($chunk));
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
                provider: config('services.statement_embeddings.provider'),
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
}
