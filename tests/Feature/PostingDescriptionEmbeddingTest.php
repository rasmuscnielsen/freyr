<?php

use App\Enums\PlatformSettingKey;
use App\Jobs\GeneratePostingEmbeddings;
use App\Models\Posting;
use App\Queries\PostingSimilarity;
use App\Services\Embeddings\EmbeddingGenerator;
use App\Support\PlatformSettings;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

/**
 * Turn the similar-postings feature on with a throwaway key — the generator
 * refuses to run without both.
 */
function enableSimilarity(): PlatformSettings
{
    $settings = app(PlatformSettings::class);
    $settings->set(PlatformSettingKey::OpenAiApiKey, 'sk-test-key');
    $settings->set(PlatformSettingKey::SimilarityEnabled, true);

    return $settings;
}

function fakeEmbeddings(): void
{
    Embeddings::fake(function (EmbeddingsPrompt $prompt): array {
        return array_map(
            fn (): array => array_fill(0, $prompt->dimensions, 0.01),
            $prompt->inputs,
        );
    })->preventStrayEmbeddings();
}

test('posting description embeddings can be generated for uncached postings', function () {
    enableSimilarity();
    fakeEmbeddings();

    $first = Posting::factory()->create(['description' => 'Example Market']);
    $second = Posting::factory()->create(['description' => 'Netflix subscription']);

    $count = app(EmbeddingGenerator::class)->generateMissing(limit: 10, batchSize: 2);

    expect($count)->toBe(2)
        ->and($first->refresh()->description_embedding)->toHaveCount(1536)
        ->and($first->description_embedded_at)->not->toBeNull()
        ->and($second->refresh()->description_embedding)->toHaveCount(1536);

    Embeddings::assertGenerated(
        fn (EmbeddingsPrompt $prompt): bool => $prompt->contains('Example Market')
            && $prompt->contains('Netflix subscription')
            && $prompt->dimensions === 1536,
    );
});

test('the generator is a no-op until an api key exists and the feature is enabled', function () {
    config()->set('ai.providers.openai.key', null);
    Embeddings::fake()->preventStrayEmbeddings();

    Posting::factory()->create(['description' => 'Example Market']);

    $generator = app(EmbeddingGenerator::class);

    // Neither enabled nor keyed.
    expect($generator->generateMissing(limit: 10))->toBe(0);

    // Enabled without a key still cannot run.
    app(PlatformSettings::class)->set(PlatformSettingKey::SimilarityEnabled, true);
    expect($generator->generateMissing(limit: 10))->toBe(0);

    // Keyed but not enabled stays off too.
    $settings = app(PlatformSettings::class);
    $settings->set(PlatformSettingKey::SimilarityEnabled, false);
    $settings->set(PlatformSettingKey::OpenAiApiKey, 'sk-test-key');
    expect($generator->generateMissing(limit: 10))->toBe(0)
        ->and(Posting::query()->sole()->description_embedding)->toBeNull();
});

test('the lookback window skips older postings', function () {
    $settings = enableSimilarity();
    $settings->set(PlatformSettingKey::SimilaritySince, '1y');
    fakeEmbeddings();

    $old = Posting::factory()->create([
        'description' => 'Ancient posting',
        'date' => now()->subYears(2)->toDateString(),
    ]);
    $recent = Posting::factory()->create([
        'description' => 'Recent posting',
        'date' => now()->subMonth()->toDateString(),
    ]);

    app(EmbeddingGenerator::class)->generateMissing(limit: 10);

    expect($old->refresh()->description_embedding)->toBeNull()
        ->and($recent->refresh()->description_embedding)->toHaveCount(1536);
});

test('the sweep job embeds missing postings when the feature is available', function () {
    enableSimilarity();
    fakeEmbeddings();

    Posting::factory()->create(['description' => 'Sweep me']);

    (new GeneratePostingEmbeddings)->handle(
        app(EmbeddingGenerator::class),
        app(PostingSimilarity::class),
    );

    expect(Posting::query()->sole()->description_embedding)->toHaveCount(1536);
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
    enableSimilarity();
    fakeEmbeddings();

    Posting::factory()->create(['description' => 'Salary']);

    $this->artisan('app:postings:embed-descriptions', ['--limit' => 5, '--batch' => 1])
        ->expectsOutputToContain('Generated description embeddings for 1 postings.')
        ->assertSuccessful();
});
