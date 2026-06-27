<?php

namespace App\Console\Commands;

use App\Finance\PostingDescriptionEmbeddingGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:postings:embed-descriptions {--limit=100 : Maximum postings to embed in this run} {--batch= : Embedding API batch size}')]
#[Description('Generate embeddings for posting descriptions that do not have one yet')]
class GeneratePostingDescriptionEmbeddingsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PostingDescriptionEmbeddingGenerator $embeddings): int
    {
        $count = $embeddings->generateMissing(
            limit: max(1, (int) $this->option('limit')),
            batchSize: $this->option('batch') === null ? null : max(1, (int) $this->option('batch')),
        );

        $this->components->info("Generated description embeddings for {$count} postings.");

        return self::SUCCESS;
    }
}
