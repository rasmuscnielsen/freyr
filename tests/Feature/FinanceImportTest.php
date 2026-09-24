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
        ->and(Posting::query()->count())->toBe(5)
        ->and(User::query()->count())->toBe(1);

    $user = User::query()->firstOrFail();
    expect($user->email)->toBe('jane@example.com')
        ->and($user->name)->toBe('Jane Importer');

    $travelCard = BankAccount::query()->where('legacy_id', 'acc-2')->firstOrFail();
    expect($travelCard->currency)->toBe('EUR')
        ->and($travelCard->bank_name)->toBe('Wise');

    $salary = Posting::query()->where('legacy_id', 'post-1')->firstOrFail();
    expect((float) $salary->amount)->toBe(30000.0)
        ->and((float) $salary->balance)->toBe(31500.50)
        ->and($salary->date->toDateString())->toBe('2024-01-01')
        ->and($salary->categorizedSubcategory->name)->toBe('Salary');

    $transferOut = Posting::query()->where('legacy_id', 'post-3')->firstOrFail();
    $transferIn = Posting::query()->where('legacy_id', 'post-4')->firstOrFail();
    expect($transferOut->counter_entry_posting_id)->toBe($transferIn->id)
        ->and($transferIn->counter_entry_posting_id)->toBe($transferOut->id);

    // Spiir's legacy 'Transfer' category type collapses onto Exclude.
    expect(Category::query()->where('legacy_id', '300')->firstOrFail()->type)->toBe(CategoryType::Exclude);

    $this->artisan('app:spiir:import', ['path' => $path])
        ->assertSuccessful();

    expect(Category::query()->count())->toBe(3)
        ->and(Subcategory::query()->count())->toBe(3)
        ->and(BankAccount::query()->count())->toBe(2)
        ->and(Posting::query()->count())->toBe(5)
        ->and(User::query()->count())->toBe(1);
});

test('it imports a spiir export for an explicit user', function () {
    $user = User::factory()->create();
    $path = base_path('tests/Fixtures/spiir-export');

    $summary = app(SpiirExportImporter::class)->importExport($path, $user);

    expect($summary['user_id'])->toBe($user->id)
        ->and(User::query()->count())->toBe(1)
        ->and(Posting::query()->count())->toBe(5);
});

test('it fails when the export path is not a directory', function () {
    $this->artisan('app:spiir:import', ['path' => base_path('tests/Fixtures/does-not-exist')])
        ->assertFailed();
});
