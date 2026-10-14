<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Follows `meta.next_cursor` until exhausted and returns the visited ids.
 *
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function iteratePostings(string $uri, array $query = [], ?Closure $afterFirstPage = null): array
{
    $ids = [];
    $cursor = null;

    do {
        $response = test()->getJson($uri.'?'.http_build_query([...$query, 'cursor' => $cursor]))->assertOk();
        $ids = [...$ids, ...array_column($response->json('data'), 'id')];
        $cursor = $response->json('meta.next_cursor');

        if ($afterFirstPage !== null) {
            $afterFirstPage();
            $afterFirstPage = null;
        }
    } while ($cursor !== null);

    return $ids;
}

test('postings can be read by their owner', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create(['name' => 'Checking']);
    $category = Category::factory()->create(['name' => 'Housing']);
    $subcategory = Subcategory::factory()->forCategory($category)->create(['name' => 'Rent']);
    $posting = Posting::factory()
        ->forAccount($account)
        ->categorizedAs($subcategory)
        ->create(['description' => 'Monthly rent', 'amount' => -12000]);
    Posting::factory()->create(['description' => 'Other posting']);

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/postings')
        ->assertOk()
        ->assertJsonPath('data.0.id', $posting->id)
        ->assertJsonPath('data.0.bank_account.name', 'Checking')
        ->assertJsonPath('data.0.category.name', 'Housing')
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/postings/{$posting->id}")
        ->assertOk()
        ->assertJsonPath('data.description', 'Monthly rent');
});

test('postings cannot be read across users', function () {
    Passport::actingAs(User::factory()->create(), ['read']);

    $posting = Posting::factory()->create();

    $this->getJson("/api/postings/{$posting->id}")->assertNotFound();
});

test('postings can be listed per bank account', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $otherAccount = BankAccount::factory()->for($user)->create();
    $posting = Posting::factory()->forAccount($account)->create();
    Posting::factory()->forAccount($otherAccount)->create();

    Passport::actingAs($user, ['read']);

    $this->getJson("/api/bank-accounts/{$account->id}/postings")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $posting->id);

    $this->getJson('/api/bank-accounts/'.BankAccount::factory()->create()->id.'/postings')->assertNotFound();
});

test('postings can be filtered by an inclusive booking date range', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $ids = collect(['2026-02-28', '2026-03-01', '2026-03-15', '2026-03-31', '2026-04-01'])
        ->mapWithKeys(fn (string $date) => [$date => Posting::factory()->forAccount($account)->create(['date' => $date])->id]);

    Passport::actingAs($user, ['read']);

    $this->getJson("/api/bank-accounts/{$account->id}/postings?filter[date_from]=2026-03-01&filter[date_to]=2026-03-31&sort=date")
        ->assertOk()
        ->assertJsonPath('data.*.id', [$ids['2026-03-01'], $ids['2026-03-15'], $ids['2026-03-31']]);

    // Open-ended bound.
    $this->getJson('/api/postings?filter[date_from]=2026-03-31&sort=date')
        ->assertJsonPath('data.*.id', [$ids['2026-03-31'], $ids['2026-04-01']]);
});

test('invalid posting filters are rejected', function (array $query, string $field) {
    Passport::actingAs(User::factory()->create(), ['read']);

    $this->getJson('/api/postings?'.http_build_query($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'malformed date' => [['filter' => ['date_from' => '03/01/2026']], 'filter.date_from'],
    'impossible date' => [['filter' => ['date_to' => '2026-02-31']], 'filter.date_to'],
    'reversed range' => [['filter' => ['date_from' => '2026-03-02', 'date_to' => '2026-03-01']], 'filter.date_to'],
    'non-numeric amount' => [['filter' => ['amount_min' => 'lots']], 'filter.amount_min'],
    'impossible legacy range' => [['filter' => ['posted_between' => '2026-02-31,']], 'filter.posted_between'],
    'reversed legacy range' => [['filter' => ['posted_between' => '2026-03-02,2026-03-01']], 'filter.posted_between'],
    'reversed amounts' => [['filter' => ['amount_min' => '10', 'amount_max' => '-10']], 'filter.amount_max'],
    'account id beyond bigint' => [['filter' => ['account' => '1,9223372036854775808']], 'filter.account'],
    'non-ISO timestamp' => [['filter' => ['changed_since' => 'Sat, 02 May 2026 09:00:00 +0000']], 'filter.changed_since'],
    'undecodable cursor' => [['cursor' => 'not-a-cursor'], 'cursor'],
    'cursor without date' => [['cursor' => base64_encode(json_encode(['id' => 1, '_pointsToNextItems' => true]))], 'cursor'],
    'cursor with bad date' => [['cursor' => base64_encode(json_encode(['date' => 'not-a-date', 'id' => 1, '_pointsToNextItems' => true]))], 'cursor'],
    'cursor id beyond bigint' => [['cursor' => base64_encode(json_encode(['date' => '2026-01-01', 'id' => '9223372036854775808', '_pointsToNextItems' => true]))], 'cursor'],
    'unknown sort' => [['sort' => 'amount'], 'sort'],
    'unknown include' => [['include' => 'user'], 'include'],
    'oversized page' => [['per_page' => 101], 'per_page'],
]);

test('cursor iteration visits every posting once even when postings arrive mid-iteration', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    // Shared dates force the id tie-breaker to matter at page boundaries.
    $existing = collect(['2026-01-01', '2026-01-02', '2026-01-02', '2026-01-02', '2026-01-03'])
        ->map(fn (string $date) => Posting::factory()->forAccount($account)->create(['date' => $date]));

    Passport::actingAs($user, ['read']);

    $newestFirst = $existing->sortBy([['date', 'desc'], ['id', 'desc']])->pluck('id')->all();
    $late = null;

    $visited = iteratePostings('/api/postings', ['per_page' => 2], function () use ($account, &$late) {
        $late = Posting::factory()->forAccount($account)->create(['date' => '2026-01-04']);
    });

    // The late, newest posting sorts before the cursor and is not visited;
    // nothing shifts into a duplicate or a gap.
    expect($visited)->toBe($newestFirst)->not->toContain($late->id);

    $oldestFirst = $existing->push($late)->sortBy([['date', 'asc'], ['id', 'asc']])->pluck('id')->all();

    expect(iteratePostings('/api/postings', ['per_page' => 2, 'sort' => 'date']))->toBe($oldestFirst);
});

test('postings can be synced incrementally by update time', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();

    Carbon::setTestNow('2026-05-01 10:00:00');
    $unchanged = Posting::factory()->forAccount($account)->create();
    $edited = Posting::factory()->forAccount($account)->create();

    Carbon::setTestNow('2026-05-02 10:00:00');
    $edited->update(['comment' => 'Edited']);
    $created = Posting::factory()->forAccount($account)->create();

    Passport::actingAs($user, ['read']);

    $ids = array_column($this->getJson('/api/postings?filter[changed_since]=2026-05-02T09:00:00Z')->assertOk()->json('data'), 'id');

    expect($ids)->toEqualCanonicalizing([$edited->id, $created->id])->not->toContain($unchanged->id);
});

test('postings can be filtered by an upper amount bound alone', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $expense = Posting::factory()->forAccount($account)->create(['amount' => -10]);
    Posting::factory()->forAccount($account)->create(['amount' => -9.99]);

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/postings?filter[amount_max]=-10')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$expense->id]);
});

test('text search treats commas literally', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $match = Posting::factory()->forAccount($account)->create(['description' => 'ACME, Inc. invoice']);
    Posting::factory()->forAccount($account)->create(['description' => 'ACME Inc. invoice']);

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/postings?'.http_build_query(['filter' => ['text' => 'ACME, Inc.']]))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$match->id]);
});

test('deleted postings are only listed, marked, under changed_since', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();

    Carbon::setTestNow('2026-05-01 10:00:00');
    $kept = Posting::factory()->forAccount($account)->create(['amount' => -10]);
    $deleted = Posting::factory()->forAccount($account)->create(['amount' => -99]);
    $deletedEarlier = Posting::factory()->forAccount($account)->create();
    $deletedEarlier->delete();

    Carbon::setTestNow('2026-05-02 10:00:00');
    $deleted->delete();

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/postings')->assertJsonPath('data.*.id', [$kept->id]);
    $this->getJson("/api/postings/{$deleted->id}")->assertNotFound();

    $this->getJson('/api/postings?filter[changed_since]=2026-05-02T09:00:00Z&with_totals=1')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$deleted->id])
        ->assertJsonPath('data.0.deleted_at', '2026-05-02T10:00:00.000000Z')
        ->assertJsonPath('meta.totals.count', 0);

    $deletedAt = collect($this->getJson("/api/bank-accounts/{$account->id}/postings?filter[changed_since]=2026-05-01T00:00:00Z")->json('data'))
        ->pluck('deleted_at', 'id');

    expect($deletedAt->all())->toEqual([
        $kept->id => null,
        $deleted->id => '2026-05-02T10:00:00.000000Z',
        $deletedEarlier->id => '2026-05-01T10:00:00.000000Z',
    ]);
});

test('postings whose category is deleted show up in incremental sync', function (string $deleted) {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $subcategory = Subcategory::factory()->forCategory(Category::factory()->create())->create();

    Carbon::setTestNow('2026-05-01 10:00:00');
    $posting = Posting::factory()->forAccount($account)->categorizedAs($subcategory)->create();

    Carbon::setTestNow('2026-05-02 10:00:00');
    ($deleted === 'category' ? $subcategory->category : $subcategory)->delete();

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/postings?filter[changed_since]=2026-05-02T09:00:00Z')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$posting->id])
        ->assertJsonPath('data.0.subcategory', null);
})->with(['subcategory', 'category']);

test('postings losing their category are stamped when the delete commits, not before', function () {
    $subcategory = Subcategory::factory()->forCategory(Category::factory()->create())->create();
    $posting = Posting::factory()->categorizedAs($subcategory)->create();

    // A sync starting between the delete and its commit reads the old row;
    // the stamp must not predate that sync's start.
    DB::transaction(function () use ($subcategory) {
        Carbon::setTestNow('2026-05-02 10:00:00');
        $subcategory->delete();
        Carbon::setTestNow('2026-05-02 10:00:05');
    });

    expect($posting->fresh()->updated_at->toDateTimeString())->toBe('2026-05-02 10:00:05');
});

test('postings can be filtered by amount range and several accounts', function () {
    $user = User::factory()->create();
    $first = BankAccount::factory()->for($user)->create();
    $second = BankAccount::factory()->for($user)->create();
    $third = BankAccount::factory()->for($user)->create();

    $inRange = Posting::factory()->forAccount($first)->create(['amount' => -100]);
    $lowerEdge = Posting::factory()->forAccount($second)->create(['amount' => -500]);
    Posting::factory()->forAccount($first)->create(['amount' => -500.01]);
    Posting::factory()->forAccount($first)->create(['amount' => 0.01]);
    Posting::factory()->forAccount($third)->create(['amount' => -100]);

    Passport::actingAs($user, ['read']);

    $ids = array_column($this->getJson("/api/postings?filter[account]={$first->id},{$second->id}&filter[amount_min]=-500&filter[amount_max]=0")
        ->assertOk()->json('data'), 'id');

    expect($ids)->toEqualCanonicalizing([$inRange->id, $lowerEdge->id]);
});

test('embedded relations can be chosen with include', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $subcategory = Subcategory::factory()->forCategory(Category::factory()->create())->create();
    Posting::factory()->forAccount($account)->categorizedAs($subcategory)->create();

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/postings?include=')
        ->assertOk()
        ->assertJsonMissingPath('data.0.bank_account')
        ->assertJsonMissingPath('data.0.category');

    $this->getJson('/api/postings?include=category')
        ->assertJsonMissingPath('data.0.bank_account')
        ->assertJsonPath('data.0.category.id', $subcategory->category_id);
});

test('totals cover the whole filtered set rather than the page', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    Posting::factory()->forAccount($account)->create(['amount' => -10.25, 'date' => '2026-03-01']);
    Posting::factory()->forAccount($account)->create(['amount' => 100, 'date' => '2026-03-02']);
    Posting::factory()->forAccount($account)->create(['amount' => -30.5, 'date' => '2026-03-03']);
    Posting::factory()->forAccount($account)->create(['amount' => -999, 'date' => '2026-04-01']);

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/postings?filter[date_to]=2026-03-31&per_page=1&with_totals=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.totals.count', 3)
        ->assertJsonPath('meta.totals.amount_sum', 59.25)
        ->assertJsonPath('meta.per_page', 1);

    $this->getJson('/api/postings')->assertJsonMissingPath('meta.totals');
});
