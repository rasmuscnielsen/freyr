<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Passport\Passport;

test('report endpoints require authentication', function () {
    $this->getJson('/api/reports/overview')->assertUnauthorized();
    $this->getJson('/api/reports/overview/categories')->assertUnauthorized();
});

test('report endpoints require a read token ability', function () {
    Passport::actingAs(User::factory()->create(), ['write']);

    $this->getJson('/api/reports/overview')->assertForbidden();
    $this->getJson('/api/reports/overview/categories')->assertForbidden();
});

test('the overview report aggregates monthly buckets over the requested window', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');

    try {
        $user = User::factory()->create(['reporting_currency' => 'DKK']);
        $account = BankAccount::factory()->for($user)->create();
        $salary = Subcategory::factory()->forCategory(Category::factory()->income()->create())->create();
        $rent = Subcategory::factory()->forCategory(Category::factory()->create())->create(['expense_group' => 'Bills']);

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-01-05', 'amount' => 10000, 'original_amount' => 10000]);
        Posting::factory()->forAccount($account)->categorizedAs($rent)
            ->create(['date' => '2026-01-10', 'amount' => -1500, 'original_amount' => -1500]);
        // Outside the requested window.
        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2024-01-05', 'amount' => 99999, 'original_amount' => 99999]);
        // Deleted postings no longer count.
        Posting::factory()->forAccount($account)->categorizedAs($rent)
            ->create(['date' => '2026-01-11', 'amount' => -400, 'original_amount' => -400])
            ->delete();
        // Another user's postings never leak in.
        Posting::factory()->create(['date' => '2026-01-06', 'amount' => 7777, 'original_amount' => 7777]);

        Passport::actingAs($user, ['read']);

        $this->getJson('/api/reports/overview?interval=month&from=2026-01&to=2026-03')
            ->assertOk()
            ->assertJsonPath('data.interval', 'month')
            ->assertJsonPath('data.from', '2026-01-01')
            ->assertJsonPath('data.to', '2026-03-31')
            ->assertJsonPath('data.currency', 'DKK')
            ->assertJsonPath('data.summary.income', 10000)
            ->assertJsonPath('data.summary.bills', 1500)
            ->assertJsonPath('data.summary.out', 1500)
            ->assertJsonPath('data.summary.result', 8500)
            ->assertJsonCount(3, 'data.series')
            ->assertJsonPath('data.series.0.period', '2026-01')
            ->assertJsonPath('data.series.0.complete', true)
            ->assertJsonPath('data.series.0.income', 10000)
            ->assertJsonPath('data.series.1.period', '2026-02')
            ->assertJsonPath('data.series.1.result', 0);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('the overview report aggregates yearly when interval=year', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $salary = Subcategory::factory()->forCategory(Category::factory()->income()->create())->create();

    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2024-06-05', 'amount' => 8000, 'original_amount' => 8000]);
    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2025-06-05', 'amount' => 12000, 'original_amount' => 12000]);

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/reports/overview?interval=year&from=2024&to=2025')
        ->assertOk()
        ->assertJsonPath('data.interval', 'year')
        ->assertJsonPath('data.from', '2024-01-01')
        ->assertJsonPath('data.to', '2025-12-31')
        ->assertJsonPath('data.summary.income', 20000)
        ->assertJsonCount(2, 'data.series')
        ->assertJsonPath('data.series.0.period', '2024')
        ->assertJsonPath('data.series.0.income', 8000)
        ->assertJsonPath('data.series.1.period', '2025')
        ->assertJsonPath('data.series.1.income', 12000);
});

test('the categories report totals the window per category and can split by period', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $income = Category::factory()->income()->create(['name' => 'Income']);
    $salary = Subcategory::factory()->forCategory($income)->create(['name' => 'Salary']);
    $housing = Category::factory()->create(['name' => 'Housing']);
    $rent = Subcategory::factory()->forCategory($housing)->create(['name' => 'Rent', 'expense_group' => 'Bills']);
    $groceries = Subcategory::factory()->forCategory($housing)->create(['name' => 'Groceries', 'expense_group' => 'LivingCosts']);

    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2025-01-05', 'amount' => 10000, 'original_amount' => 10000]);
    Posting::factory()->forAccount($account)->categorizedAs($rent)
        ->create(['date' => '2025-01-10', 'amount' => -1500, 'original_amount' => -1500]);
    Posting::factory()->forAccount($account)->categorizedAs($groceries)
        ->create(['date' => '2026-02-12', 'amount' => -400, 'original_amount' => -400]);

    Passport::actingAs($user, ['read']);

    // Window totals: raw sums per category with subcategory rollups.
    $this->getJson('/api/reports/overview/categories?from=2025&to=2026')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Income')
        ->assertJsonPath('data.0.type', 'income')
        ->assertJsonPath('data.0.total', 10000)
        ->assertJsonPath('data.1.name', 'Housing')
        ->assertJsonPath('data.1.type', 'expense')
        ->assertJsonPath('data.1.bucket', 'bills')
        ->assertJsonPath('data.1.total', 1900)
        ->assertJsonCount(2, 'data.1.subcategories')
        ->assertJsonPath('data.1.top_postings.0.amount', 1500)
        ->assertJsonMissingPath('data.0.series');

    // With an interval each category additionally reports per-period totals.
    $this->getJson('/api/reports/overview/categories?from=2025&to=2026&interval=year')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Income')
        ->assertJsonCount(1, 'data.0.series')
        ->assertJsonPath('data.0.series.0.period', '2025')
        ->assertJsonPath('data.0.series.0.total', 10000)
        ->assertJsonPath('data.1.series.0.period', '2025')
        ->assertJsonPath('data.1.series.0.total', 1500)
        ->assertJsonPath('data.1.series.1.period', '2026')
        ->assertJsonPath('data.1.series.1.total', 400);
});

test('uncategorized income and expense each report their own period series', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();

    // No subcategory: a positive amount buckets as income, a negative one
    // as expense — both land on category id null.
    Posting::factory()->forAccount($account)->uncategorized()
        ->create(['date' => '2025-01-05', 'amount' => 100, 'original_amount' => 100]);
    Posting::factory()->forAccount($account)->uncategorized()
        ->create(['date' => '2025-01-10', 'amount' => -40, 'original_amount' => -40]);

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/reports/overview/categories?from=2025&to=2025&interval=month')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'income')
        ->assertJsonPath('data.0.total', 100)
        ->assertJsonPath('data.0.series.0.total', 100)
        ->assertJsonPath('data.1.type', 'expense')
        ->assertJsonPath('data.1.total', 40)
        ->assertJsonPath('data.1.series.0.total', 40);
});

test('report windows and intervals are validated', function () {
    Passport::actingAs(User::factory()->create(), ['read']);

    $this->getJson('/api/reports/overview?interval=bogus')->assertUnprocessable();
    $this->getJson('/api/reports/overview?from=garbage')->assertUnprocessable();
    $this->getJson('/api/reports/overview?from=2026-13')->assertUnprocessable();
    $this->getJson('/api/reports/overview?from=2026-05&to=2026-01')->assertUnprocessable();
    $this->getJson('/api/reports/overview/categories?interval=bogus')->assertUnprocessable();
});

test('report windows default to the full data range', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');

    try {
        $user = User::factory()->create();
        $account = BankAccount::factory()->for($user)->create();
        $salary = Subcategory::factory()->forCategory(Category::factory()->income()->create())->create();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2024-06-05', 'amount' => 8000, 'original_amount' => 8000]);

        Passport::actingAs($user, ['read']);

        $this->getJson('/api/reports/overview?interval=year')
            ->assertOk()
            ->assertJsonPath('data.from', '2024-01-01')
            ->assertJsonPath('data.to', '2026-07-15')
            ->assertJsonCount(3, 'data.series');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('category nets keep the refund direction that the magnitude fields discard', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $housing = Category::factory()->create(['name' => 'Housing']);
    $rent = Subcategory::factory()->forCategory($housing)->create(['name' => 'Rent', 'expense_group' => 'Bills']);
    $repairs = Subcategory::factory()->forCategory($housing)->create(['name' => 'Repairs', 'expense_group' => 'Bills']);
    $gifts = Subcategory::factory()->forCategory($housing)->create(['name' => 'Gifts', 'expense_group' => 'Bills']);
    $shopping = Category::factory()->create(['name' => 'Shopping']);
    $returns = Subcategory::factory()->forCategory($shopping)->create(['name' => 'Returns', 'expense_group' => 'Lifestyle']);
    $salary = Subcategory::factory()->forCategory(Category::factory()->income()->create(['name' => 'Income']))->create(['name' => 'Salary']);

    $post = fn (Subcategory $subcategory, string $date, ?float $amount) => Posting::factory()->forAccount($account)
        ->categorizedAs($subcategory)->create(['date' => $date, 'amount' => $amount, 'original_amount' => $amount ?? -1]);

    // Rent: spend 100, refund 20 → net movement −80 (magnitude 80).
    $post($rent, '2025-01-05', -100);
    $post($rent, '2025-01-20', 20);
    // Repairs: spend 20, refund 100 → net movement +80; magnitude hides the sign.
    $post($repairs, '2025-01-06', -20);
    $post($repairs, '2025-02-06', 100);
    // Gifts: refund and spend cancel exactly; plus a posting without FX.
    $post($gifts, '2025-01-07', -50);
    $post($gifts, '2025-01-08', 50);
    $post($gifts, '2025-01-09', null);
    // A whole category that is a net refund.
    $post($returns, '2025-01-10', 70);
    // Income reversal: 1000 salary, 1200 clawed back → −200.
    $post($salary, '2025-01-11', 1000);
    $post($salary, '2025-02-11', -1200);

    Passport::actingAs($user, ['read']);

    $data = collect($this->getJson('/api/reports/overview/categories?from=2025&to=2025&interval=month')
        ->assertOk()->json('data'))->keyBy('name');

    $housingRow = $data['Housing'];
    // Unsigned presentation fields are unchanged: |80| + |−80| + |0| = 160 ≠ |0|.
    expect($housingRow['total'])->toEqual(0.0)
        ->and($housingRow['net_total'])->toEqual(0.0)
        ->and($housingRow['missing'])->toEqual(1);

    $subs = collect($housingRow['subcategories'])->keyBy('name');
    expect($subs['Rent']['amount'])->toEqual(80.0)->and($subs['Rent']['net_amount'])->toEqual(-80.0)
        ->and($subs['Repairs']['amount'])->toEqual(80.0)->and($subs['Repairs']['net_amount'])->toEqual(80.0)
        ->and($subs['Gifts']['net_amount'])->toEqual(0.0)
        // Subcategory nets reconcile to the category net.
        ->and(collect($housingRow['subcategories'])->sum('net_amount'))->toBe($housingRow['net_total'])
        // Period nets reconcile to the category net.
        ->and(collect($housingRow['series'])->sum('net'))->toBe($housingRow['net_total'])
        ->and($housingRow['series'][1]['period'])->toEqual('2025-02')
        ->and($housingRow['series'][1]['net'])->toEqual(100.0)
        ->and($housingRow['series'][1]['total'])->toEqual(100.0);

    // Whole-category net refund: magnitude 70, net +70, still on the expense side.
    expect($data['Shopping']['type'])->toEqual('expense')
        ->and($data['Shopping']['total'])->toEqual(70.0)
        ->and($data['Shopping']['net_total'])->toEqual(70.0);

    // Income reversal: a net clawback stays negative.
    expect($data['Income']['total'])->toEqual(200.0)
        ->and($data['Income']['net_total'])->toEqual(-200.0)
        ->and($data['Income']['subcategories'][0]['net_amount'])->toEqual(-200.0)
        ->and(collect($data['Income']['series'])->pluck('net')->all())->toEqual([1000, -1200]);
});

test('uncategorized flows split by sign into income and expense nets, with no negative zero', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();

    Posting::factory()->forAccount($account)->uncategorized()
        ->create(['date' => '2025-01-05', 'amount' => -40, 'original_amount' => -40]);
    Posting::factory()->forAccount($account)->uncategorized()
        ->create(['date' => '2025-01-06', 'amount' => 25, 'original_amount' => 25]);

    Passport::actingAs($user, ['read']);

    $response = $this->getJson('/api/reports/overview/categories?from=2025&to=2025&interval=month')->assertOk();
    $data = collect($response->json('data'))->keyBy('type');

    expect($data)->toHaveCount(2)
        ->and($data['expense']['net_total'])->toEqual(-40.0)
        ->and($data['expense']['subcategories'][0]['net_amount'])->toEqual(-40.0)
        ->and($data['income']['net_total'])->toEqual(25.0);
});

test('a zero net never serialises as negative zero', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $rent = Subcategory::factory()->forCategory(Category::factory()->create())->create(['expense_group' => 'Bills']);

    foreach ([-50, 50] as $amount) {
        Posting::factory()->forAccount($account)->categorizedAs($rent)
            ->create(['date' => '2025-01-05', 'amount' => $amount, 'original_amount' => $amount]);
    }

    Passport::actingAs($user, ['read']);

    $content = $this->getJson('/api/reports/overview/categories?from=2025&to=2025&interval=month')
        ->assertOk()->getContent();

    expect($content)->toContain('"net_total":0')->not->toContain('"net_total":-0');
});
