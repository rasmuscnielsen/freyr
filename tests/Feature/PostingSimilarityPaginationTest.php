<?php

use App\Enums\PlatformSettingKey;
use App\Models\BankAccount;
use App\Models\Posting;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;

test('equally similar postings load newest first with stable pagination for matching dates', function () {
    $settings = app(PlatformSettings::class);
    $settings->set(PlatformSettingKey::OpenAiApiKey, 'sk-test-key');
    $settings->set(PlatformSettingKey::SimilarityEnabled, true);
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $embedding = array_fill(0, 1536, 0.1);
    $posting = Posting::factory()->forAccount($account)->create(['description_embedding' => $embedding]);
    $matches = Posting::factory()->count(10)->forAccount($account)
        ->sequence(fn (Sequence $sequence): array => ['date' => '2026-09-'.sprintf('%02d', intdiv($sequence->index, 2) + 1)])
        ->create(['description_embedding' => $embedding]);
    Posting::factory()->create(['description_embedding' => $embedding]);

    $first = $this->actingAs($user)->getJson(route('postings.similar', $posting))
        ->assertJsonPath('has_more_similar', true)
        ->assertJsonCount(8, 'similar');
    $last = $this->getJson(route('postings.similar', ['posting' => $posting, 'offset' => 8]))
        ->assertJsonPath('has_more_similar', false)
        ->assertJsonCount(2, 'similar');

    expect(array_column($first->json('similar'), 'id'))->toBe([
        $matches[8]->id, $matches[9]->id,
        $matches[6]->id, $matches[7]->id,
        $matches[4]->id, $matches[5]->id,
        $matches[2]->id, $matches[3]->id,
    ])->and(array_column($last->json('similar'), 'id'))->toBe([$matches[0]->id, $matches[1]->id]);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Requires pgvector similarity queries');

test('similar postings have no more batches when similarity is unavailable', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $posting = Posting::factory()->forAccount($account)->create();

    $this->actingAs($user)->getJson(route('postings.similar', $posting))
        ->assertJsonPath('has_more_similar', false)
        ->assertJsonCount(0, 'similar');
});

test('similar posting pagination rejects invalid offsets', function (mixed $offset) {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $posting = Posting::factory()->forAccount($account)->create();

    $this->actingAs($user)->getJson(route('postings.similar', ['posting' => $posting, 'offset' => $offset]))
        ->assertRedirect()
        ->assertSessionHasErrors('offset');
})->with([-1, 'invalid', 1.5]);

test('loading more similar postings cannot access another users posting', function () {
    $user = User::factory()->create();
    $posting = Posting::factory()->create();

    $this->actingAs($user)->getJson(route('postings.similar', ['posting' => $posting, 'offset' => 8]))
        ->assertNotFound();
});
