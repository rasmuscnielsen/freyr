<?php

use App\Models\BankAccount;
use App\Models\Posting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function bankPostingIdentityMigration(): object
{
    return require database_path('migrations/2026_09_28_223052_remove_external_account_uid_hash_from_postings_table.php');
}

test('the pending migration preserves historical rows and canonicalizes bank references', function () {
    $migration = bankPostingIdentityMigration();
    $migration->down();
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    foreach (['spiir', 'manual'] as $source) {
        Posting::factory()->count(2)->for($user)->for($account)->create([
            'source' => $source, 'external_posting_id' => '*** hidden ***',
        ]);
    }
    $bank = Posting::factory()->for($user)->for($account)->create([
        'source' => 'enable_banking', 'external_posting_id' => 'mutable-detail-id',
        'extra_data' => ['entry_reference' => 'durable-reference'],
    ]);
    $legacyIds = Posting::query()->orderBy('id')->pluck('legacy_id')->all();

    DB::transaction(fn () => $migration->up());

    expect(Posting::query()->count())->toBe(5)
        ->and(Posting::query()->whereNull('external_posting_id')->count())->toBe(4)
        ->and(Posting::query()->orderBy('id')->pluck('legacy_id')->all())->toBe($legacyIds)
        ->and($bank->refresh()->external_posting_id)->toBe('durable-reference')
        ->and(Schema::hasColumn('postings', 'external_account_uid_hash'))->toBeFalse();
});

test('bank identity constraints reject duplicate references and duplicate fallback tuples', function (bool $hasReference) {
    $account = BankAccount::factory()->create();
    $attributes = [
        'source' => 'enable_banking',
        'external_posting_id' => $hasReference ? 'durable-reference' : null,
        'original_description' => 'Card purchase', 'date' => '2026-06-19',
        'original_amount' => '-128.00', 'original_currency' => 'DKK',
    ];
    Posting::factory()->forAccount($account)->create($attributes);

    expect(fn () => DB::transaction(fn () => Posting::factory()->forAccount($account)->create($attributes)))
        ->toThrow(UniqueConstraintViolationException::class);
})->with(['reference' => true, 'fallback' => false]);

test('a migration identity collision aborts without removing the old schema or changing IDs', function () {
    $migration = bankPostingIdentityMigration();
    $migration->down();
    $account = BankAccount::factory()->create();
    Posting::factory()->count(2)->forAccount($account)->create([
        'source' => 'enable_banking', 'external_posting_id' => 'mutable-detail-id',
        'extra_data' => ['entry_reference' => 'same-reference'],
    ]);

    expect(fn () => DB::transaction(fn () => $migration->up()))->toThrow(UniqueConstraintViolationException::class);
    expect(Schema::hasColumn('postings', 'external_account_uid_hash'))->toBeTrue()
        ->and(Posting::query()->where('external_posting_id', 'mutable-detail-id')->count())->toBe(2);
    DB::table('postings')->delete();
    $migration->up();
});

test('postgres rejects bank rows without a usable identity', function (?string $reference) {
    $account = BankAccount::factory()->create();

    expect(fn () => DB::transaction(fn () => Posting::factory()->forAccount($account)->create([
        'source' => 'enable_banking', 'external_posting_id' => $reference,
        'original_description' => null,
    ])))->toThrow(QueryException::class);
})->with(['missing fallback' => null, 'blank' => '', 'placeholder' => '*** hidden ***', 'null string' => 'null'])
    ->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'PostgreSQL identity check constraint');
