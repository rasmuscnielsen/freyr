<?php

use App\Finance\PostingDescriptionEmbeddingGenerator;
use App\Models\Posting;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

test('posting description embeddings can be generated for uncached postings', function () {
    Embeddings::fake(function (EmbeddingsPrompt $prompt): array {
        return array_map(
            fn (): array => array_fill(0, $prompt->dimensions, 0.01),
            $prompt->inputs,
        );
    })->preventStrayEmbeddings();

    $first = Posting::factory()->create(['description' => 'Rema 1000 Copenhagen']);
    $second = Posting::factory()->create(['description' => 'Netflix subscription']);

    $count = app(PostingDescriptionEmbeddingGenerator::class)->generateMissing(limit: 10, batchSize: 2);

    expect($count)->toBe(2)
        ->and($first->refresh()->description_embedding)->toHaveCount(1536)
        ->and($first->description_embedded_at)->not->toBeNull()
        ->and($second->refresh()->description_embedding)->toHaveCount(1536);

    Embeddings::assertGenerated(
        fn (EmbeddingsPrompt $prompt): bool => $prompt->contains('Rema 1000 Copenhagen')
            && $prompt->contains('Netflix subscription')
            && $prompt->dimensions === 1536,
    );
});

test('updating a posting description clears the existing embedding', function () {
    $posting = Posting::factory()->create([
        'description' => 'Old description',
        'description_embedding' => array_fill(0, 1536, 0.01),
        'description_embedded_at' => now(),
    ]);

    $posting->update(['description' => 'New description']);

    expect($posting->refresh()->description_embedding)->toBeNull()
        ->and($posting->description_embedded_at)->toBeNull();
});

test('posting description embedding command reports generated rows', function () {
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
        fn (): array => array_fill(0, $prompt->dimensions, 0.01),
        $prompt->inputs,
    ))->preventStrayEmbeddings();

    Posting::factory()->create(['description' => 'Salary']);

    $this->artisan('app:postings:embed-descriptions', ['--limit' => 5, '--batch' => 1])
        ->expectsOutputToContain('Generated description embeddings for 1 postings.')
        ->assertSuccessful();
});
