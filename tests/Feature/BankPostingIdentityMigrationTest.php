<?php

use App\Models\BankAccount;
use App\Models\Posting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function bankPostingIdentityMigration(): object
{
    return require database_path('migrations/2026_09_28_223052_remove_external_account_uid_hash_from_postings_table.php');
}

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

test('postgres rejects bank rows without a usable identity', function (?string $reference) {
    $account = BankAccount::factory()->create();

    expect(fn () => DB::transaction(fn () => Posting::factory()->forAccount($account)->create([
        'source' => 'enable_banking', 'external_posting_id' => $reference,
        'original_description' => null,
    ])))->toThrow(QueryException::class);
})->with(['missing fallback' => null, 'blank' => '', 'placeholder' => '*** hidden ***', 'null string' => 'null'])
    ->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'PostgreSQL identity check constraint');
