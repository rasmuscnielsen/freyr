<?php

namespace App\Http\Controllers;

use App\Enums\Interval;
use App\Models\BankAccount;
use App\Models\Posting;
use App\Queries\OverviewReport;
use App\Services\Frankfurter\ExchangeRateStore;
use App\Support\MoneyMath;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $latestDate = Posting::query()->forUser($user)->max('date');
        $earliestDate = Posting::query()->forUser($user)->min('date');

        if ($latestDate === null) {
            $latestPostingDate = CarbonImmutable::now()->endOfMonth();
        } else {
            $latestPostingDate = CarbonImmutable::parse($latestDate);
        }

        $now = CarbonImmutable::now();
        $currentMonthEnd = $latestPostingDate->endOfMonth()->gt($now->endOfMonth())
            ? $now->endOfMonth()
            : $latestPostingDate->endOfMonth();

        $lastCompletedMonthEnd = $currentMonthEnd->isSameMonth($now)
            ? $currentMonthEnd->subMonthNoOverflow()->endOfMonth()
            : $currentMonthEnd;

        $earliestYear = $earliestDate !== null
            ? CarbonImmutable::parse($earliestDate)->year
            : CarbonImmutable::now()->year;
        $currentYear = CarbonImmutable::now()->year;

        $yearOptions = collect(range($currentYear, $earliestYear))
            ->map(fn (int $year): string => (string) $year)
            ->values();

        $periodOptions = collect(['12m', 'years'])->merge($yearOptions)->values();

        [$period, $interval, $months, $start, $end] = $this->resolvePeriod(
            $request,
            $periodOptions,
            $earliestYear,
            $lastCompletedMonthEnd,
        );

        $chartEnd = $currentMonthEnd->gt($end) ? $currentMonthEnd : $end;

        if ($period === 'years') {
            // Every year with data appears — even a future-dated posting
            // earns its (dimmed) bar on the all-years comparison.
            $chartEnd = $chartEnd->gt($latestPostingDate->endOfYear()) ? $chartEnd : $latestPostingDate->endOfYear();
        } elseif (is_numeric($period)) {
            // A single calendar year never extends its series into the next.
            $periodYearEnd = $start->endOfYear();
            $chartEnd = $chartEnd->gt($periodYearEnd) ? $periodYearEnd : $chartEnd;
        }

        $report = OverviewReport::for($user, $start, $end, $interval, $chartEnd);

        // The breakdown can zoom into one series period — a month on monthly
        // periods, a year on the all-years comparison — via `focus`.
        $focus = (string) $request->query('focus', '');
        $focus = $report->acceptsFocus($focus) ? $focus : null;

        return Inertia::render('dashboard', [
            'period' => [
                'selected' => $period,
                'interval' => $interval->value,
                'months' => $months,
                'units' => $report->units(),
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'options' => $periodOptions,
            ],
            'summary' => $report->summary(),
            'series' => $report->series(),
            'balance' => $this->consolidatedBalance($request),
            'primaryCurrency' => $user->reporting_currency,
            'breakdown' => Inertia::defer(fn (): array => [
                'focus' => $focus,
                'categories' => $this->breakdownCategories($report, $focus),
            ]),
        ]);
    }

    /**
     * Category rows for the deferred breakdown prop. The report returns raw
     * window totals; without a focus they are averaged per period unit
     * (per month, or per year on the all-years period) for the "average"
     * view, while a focused period shows its raw totals.
     *
     * @return array<int, array<string, mixed>>
     */
    private function breakdownCategories(OverviewReport $report, ?string $focus): array
    {
        $divisor = $focus === null ? $report->units() : 1.0;

        return array_map(function (array $category) use ($divisor): array {
            $category['total'] = round($category['total'] / $divisor, 2);
            $category['topSubcategories'] = array_map(
                fn (array $subcategory): array => [
                    'name' => $subcategory['name'],
                    // Averaged over the same divisor as the category total so
                    // the tooltip's subcategory amounts sum toward it.
                    'amount' => round($subcategory['amount'] / $divisor, 2),
                ],
                array_slice($category['subcategories'], 0, 5),
            );
            unset($category['subcategories']);

            return $category;
        }, $report->categories($focus));
    }

    /**
     * Consolidated current balance in the reporting currency. Each active
     * account's balance is taken in its own currency and converted at the
     * newest cached rate — never by summing historical conversions and never
     * by calling the provider while rendering.
     *
     * @return array{total: ?float, currency: ?string, missing_accounts: int, valuation_date: ?string}
     */
    private function consolidatedBalance(Request $request): array
    {
        $quote = $request->user()->reporting_currency;
        $accounts = BankAccount::query()
            ->whereBelongsTo($request->user())
            ->where('is_active', true)
            ->get(['currency', 'current_balance']);

        $total = '0';
        $missing = 0;
        $used = false;
        $valuationDates = [];

        foreach ($accounts as $account) {
            if ($account->current_balance === null) {
                $missing++;

                continue;
            }

            if ($quote !== null && $account->currency === $quote) {
                $total = bcadd($total, (string) $account->current_balance, 2);
                $used = true;

                continue;
            }

            if ($quote === null) {
                $missing++;

                continue;
            }

            $rate = app(ExchangeRateStore::class)->rateFor($account->currency, $quote, CarbonImmutable::today());

            if ($rate === null) {
                $missing++;

                continue;
            }

            $total = bcadd($total, MoneyMath::round(bcmul((string) $account->current_balance, $rate['rate'], 20)), 2);
            $valuationDates[] = $rate['effective_date'];
            $used = true;
        }

        return [
            'total' => $used ? (float) $total : null,
            'currency' => $quote,
            'missing_accounts' => $missing,
            // The oldest effective rate caps how fresh the valuation can claim to be.
            'valuation_date' => $valuationDates === [] ? null : min($valuationDates),
        ];
    }

    /**
     * Resolves the selected period into its interval, window and label:
     * '12m' (trailing twelve completed months), 'years' (every year with
     * data, aggregated yearly) or a single calendar year.
     *
     * @param  Collection<int, string>  $periodOptions
     * @return array{0: string, 1: Interval, 2: int, 3: CarbonImmutable, 4: CarbonImmutable}
     */
    private function resolvePeriod(
        Request $request,
        Collection $periodOptions,
        int $earliestYear,
        CarbonImmutable $lastCompletedMonthEnd,
    ): array {
        $requested = (string) $request->query('period', '');

        // Back-compat: the legacy `months` param wins when `period` is absent.
        if ($requested === '' && $request->has('months')) {
            $months = min(max($request->integer('months', 12), 3), 36);
            $end = $lastCompletedMonthEnd;
            $start = $end->startOfMonth()->subMonthsNoOverflow($months - 1);

            return ['12m', Interval::Month, $months, $start, $end];
        }

        if ($requested === 'years') {
            $start = CarbonImmutable::create($earliestYear, 1, 1)->startOfDay();
            $end = $lastCompletedMonthEnd;

            if ($end->lt($start)) {
                $end = $start->endOfMonth();
            }

            $months = (int) $start->diffInMonths($end->startOfMonth()) + 1;

            return ['years', Interval::Year, $months, $start, $end];
        }

        if ($requested !== '' && is_numeric($requested) && $periodOptions->contains($requested)) {
            $year = (int) $requested;
            $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
            $yearEnd = CarbonImmutable::create($year, 12, 31)->endOfDay();
            $end = $yearEnd->gt($lastCompletedMonthEnd) ? $lastCompletedMonthEnd : $yearEnd;

            if ($end->lt($start)) {
                $end = $start->endOfMonth();
            }

            $months = (int) $start->diffInMonths($end->startOfMonth()) + 1;

            return [$requested, Interval::Month, $months, $start, $end];
        }

        // Default: last 12 completed months.
        $months = 12;
        $end = $lastCompletedMonthEnd;
        $start = $end->startOfMonth()->subMonthsNoOverflow($months - 1);

        return ['12m', Interval::Month, $months, $start, $end];
    }
}
