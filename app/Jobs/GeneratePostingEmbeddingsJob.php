<?php

namespace App\Jobs;

use App\Finance\PostingDescriptionEmbeddingGenerator;
use App\Finance\PostingSimilarity;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sweeps postings that still need an embedding (within the configured
 * lookback), then refreshes similarity-driven category suggestions. Both
 * stages are no-ops when similarity features are off, so dispatching is
 * always safe — this also powers the hourly catch-up sweep.
 */
class GeneratePostingEmbeddingsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Failed runs are not retried: the next sweep picks up the remainder
     * and embeddings already written are kept.
     */
    public int $tries = 1;

    public int $timeout = 600;

    /**
     * Upper bound on batches per run (~20k postings); the sweep finishes
     * the rest so a single run cannot monopolize the queue.
     */
    private const int MAX_ITERATIONS = 1;

    public function handle(
        PostingDescriptionEmbeddingGenerator $embeddings,
        PostingSimilarity $similarity,
    ): void {
        $iterations = 0;

        while ($iterations < self::MAX_ITERATIONS && $embeddings->generateMissing(limit: 500) > 0) {
            $iterations++;
        }

        $similarity->fillSuggestions();
        $similarity->applyAutoCategorization();
    }

    public function uniqueId(): string
    {
        return 'generate-posting-embeddings';
    }
}
