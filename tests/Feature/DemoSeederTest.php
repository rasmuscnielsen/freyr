<?php

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Posting;
use App\Models\User;
use Database\Seeders\DefaultCategoriesSeeder;
use Database\Seeders\DemoSeeder;

test('it seeds a coherent demo environment', function () {
    $this->seed(DefaultCategoriesSeeder::class);
    $this->seed(DemoSeeder::class);

    $user = User::query()->where('email', 'john@example.com')->firstOrFail();

    expect(BankAccount::query()->whereBelongsTo($user)->count())->toBe(4)
        ->and(BankConnection::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(Posting::query()->where('user_id', $user->id)->count())->toBeGreaterThan(200);

    // Every posting except the deliberate triage example must be categorized —
    // this guards against demo data referencing missing subcategory keys.
    expect(Posting::query()->whereNull('categorized_subcategory_id')->count())->toBe(1);

    expect(BankAccount::query()->pluck('currency')->unique()->all())->toBe(['EUR']);

    $latestEverydayPosting = Posting::query()
        ->whereBelongsTo(BankAccount::query()->where('legacy_id', 'seed-account-everyday')->firstOrFail(), 'bankAccount')
        ->orderByDesc('date')
        ->orderByDesc('id')
        ->firstOrFail();

    expect((float) $latestEverydayPosting->balance)
        ->toBe((float) $latestEverydayPosting->bankAccount->last_booked_balance);
});
