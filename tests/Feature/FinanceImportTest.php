<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use Illuminate\Support\Facades\Schema;

test('it imports the bundled finance export idempotently', function () {
    if (! is_file(base_path('project/data/Categories.json'))) {
        $this->markTestSkipped('Local Spiir export not present at project/data.');
    }

    $this->artisan('app:spiir:import')
        ->assertSuccessful();

    expect(Category::query()->count())->toBe(11)
        ->and(Subcategory::query()->count())->toBe(91)
        ->and(BankAccount::query()->count())->toBe(16)
        ->and(Posting::query()->count())->toBe(11569)
        ->and(Schema::hasTable('bank_api_credentials'))->toBeFalse();
    expect(Posting::query()
        ->whereNotNull('subcategory_id')
        ->whereNull('categorized_subcategory_id')
        ->count())->toBe(0);

    $posting = Posting::query()
        ->where('legacy_id', '635206446385957890')
        ->firstOrFail();

    expect((float) $posting->amount)->toBe(-61.0)
        ->and((float) $posting->balance)->toBe(14302.78)
        ->and($posting->date->toDateString())->toBe('2011-12-12')
        ->and($posting->bankAccount)->toBeInstanceOf(BankAccount::class);

    $this->artisan('app:spiir:import')
        ->assertSuccessful();

    expect(Category::query()->count())->toBe(11)
        ->and(Subcategory::query()->count())->toBe(91)
        ->and(BankAccount::query()->count())->toBe(16)
        ->and(Posting::query()->count())->toBe(11569);
});
