<?php

use App\Enums\CategoryType;
use App\Finance\SpiirExportImporter;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;

test('it imports a spiir export idempotently', function () {
    $path = base_path('tests/Fixtures/spiir-export');

    $this->artisan('app:spiir:import', ['path' => $path])
        ->assertSuccessful();

    expect(Category::query()->count())->toBe(3)
        ->and(Subcategory::query()->count())->toBe(3)
        ->and(BankAccount::query()->count())->toBe(2)
        ->and(Posting::query()->count())->toBe(7)
        ->and(User::query()->count())->toBe(1);

    $user = User::query()->firstOrFail();
    expect($user->email)->toBe('jane@example.com')
        ->and($user->name)->toBe('Jane Importer');

    $travelCard = BankAccount::query()->where('legacy_id', 'acc-2')->firstOrFail();
    expect($travelCard->currency)->toBe('EUR')
        ->and($travelCard->bank_name)->toBe('Other bank')
        // Keep Spiir's account snapshot, but do not infer an opening balance
        // or recalculate the exported posting history from it.
        ->and($travelCard->opening_balance)->toBeNull()
        ->and((float) $travelCard->current_balance)->toBe(250.00);

    $salary = Posting::query()->where('legacy_id', 'post-1')->firstOrFail();
    expect((float) $salary->amount)->toBe(30000.0)
        ->and((float) $salary->balance)->toBe(31500.50)
        ->and($salary->date->toDateString())->toBe('2024-01-01')
        ->and($salary->categorizedSubcategory->name)->toBe('Salary');

    // The foreign account keeps the EUR account movement in the original
    // pair and the export's DKK figure as the reporting amount.
    $transferIn = Posting::query()->where('legacy_id', 'post-4')->firstOrFail();
    expect((float) $transferIn->original_amount)->toBe(134.20)
        ->and($transferIn->original_currency)->toBe('EUR')
        ->and((float) $transferIn->amount)->toBe(1000.0)
        ->and($transferIn->currency)->toBe('DKK')
        ->and($transferIn->balance)->toBeNull()
        ->and(data_get($transferIn->extra_data, 'spiir_source.balance'))->toBe('2862,29');

    $cafe = Posting::query()->where('legacy_id', 'post-5')->firstOrFail();
    expect((float) $cafe->original_amount)->toBe(-12.50)
        ->and($cafe->original_currency)->toBe('EUR')
        ->and((float) $cafe->amount)->toBe(-93.20);

    $transferOut = Posting::query()->where('legacy_id', 'post-3')->firstOrFail();
    $transferIn = Posting::query()->where('legacy_id', 'post-4')->firstOrFail();
    expect($transferOut->counter_entry_posting_id)->toBe($transferIn->id)
        ->and($transferIn->counter_entry_posting_id)->toBe($transferOut->id);

    expect(Posting::query()->where('legacy_id', 'post-6')->exists())->toBeFalse()
        ->and(Posting::query()->where('legacy_id', 'post-7')->firstOrFail()->balance)->toBeNull()
        ->and(Posting::query()->where('legacy_id', 'post-8')->firstOrFail()->balance)->toBeNull()
        ->and(BankAccount::query()->where('legacy_id', 'acc-1')->firstOrFail()->current_balance)->toBe('1500.50');

    // Spiir's legacy 'Transfer' category type collapses onto Exclude.
    expect(Category::query()->where('legacy_id', '300')->firstOrFail()->type)->toBe(CategoryType::Exclude);

    $this->artisan('app:spiir:import', ['path' => $path])
        ->assertSuccessful();

    expect(Category::query()->count())->toBe(3)
        ->and(Subcategory::query()->count())->toBe(3)
        ->and(BankAccount::query()->count())->toBe(2)
        ->and(Posting::query()->count())->toBe(7)
        ->and(User::query()->count())->toBe(1);
});

test('re-import preserves Spiir snapshots but recalculates explicitly anchored manual totals', function () {
    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $path = base_path('tests/Fixtures/spiir-export');
    $importer = app(SpiirExportImporter::class);

    $importer->importExport($path, $user);
    $account = BankAccount::query()->where('legacy_id', 'acc-1')->firstOrFail();
    $account->update(['current_balance' => '777.00']);

    $importer->importExport($path, $user);

    expect($account->refresh()->opening_balance)->toBeNull()
        ->and((float) $account->current_balance)->toBe(777.00)
        ->and(Posting::query()->where('legacy_id', 'post-8')->firstOrFail()->balance)->toBeNull();

    $account->update(['opening_balance' => '1000.00', 'current_balance' => '0.00']);
    $importer->importExport($path, $user);
    $expectedTotal = bcadd('1000.00', (string) $account->postings()->sum('original_amount'), 2);

    expect($account->refresh()->current_balance)->toBe($expectedTotal);
});

test('it imports a spiir export for an explicit user', function () {
    $user = User::factory()->create();
    $path = base_path('tests/Fixtures/spiir-export');

    $summary = app(SpiirExportImporter::class)->importExport($path, $user);

    expect($summary['user_id'])->toBe($user->id)
        ->and(User::query()->count())->toBe(1)
        ->and(Posting::query()->count())->toBe(7);
});

test('it fails when the export path is not a directory', function () {
    $this->artisan('app:spiir:import', ['path' => base_path('tests/Fixtures/does-not-exist')])
        ->assertFailed();
});
