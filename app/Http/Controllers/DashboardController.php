<?php

namespace App\Http\Controllers;

use App\Enums\Bucket;
use App\Finance\Fx\ExchangeRateStore;
use App\Finance\MoneyMath;
use App\Models\BankAccount;
use App\Models\Posting;
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

        $periodOptions = collect(['12m'])->merge($yearOptions)->values();

        [$period, $months, $start, $end] = $this->resolvePeriod(
            $request,
            $periodOptions,
            $lastCompletedMonthEnd,
        );

        $chartEnd = $currentMonthEnd->gt($end) ? $currentMonthEnd : $end;

        if ($period !== '12m') {
            $periodYearEnd = $start->endOfYear();
            $chartEnd = $chartEnd->gt($periodYearEnd) ? $periodYearEnd : $chartEnd;
        }

        $chartMonths = (int) $start->diffInMonths($chartEnd->startOfMonth()) + 1;

        $postings = Posting::query()
            ->forUser($user)
            ->with(['categorizedSubcategory.category'])
            ->whereBetween('date', [$start->toDateString(), $chartEnd->endOfMonth()->toDateString()])
            ->get();

        // Every posting that lands in an overview bucket, indexed alongside its
        // resolved bucket so the mapping is computed once.
        $overviewPostings = $postings
            ->map(fn (Posting $posting): array => ['posting' => $posting, 'bucket' => $posting->overviewBucket()])
            ->filter(fn (array $entry): bool => $entry['bucket'] instanceof Bucket)
            ->values();

        $monthly = collect(range(0, $chartMonths - 1))
            ->mapWithKeys(function (int $offset) use ($start): array {
                $month = $start->addMonths($offset);

                return [$month->format('Y-m') => $this->emptyMonthlyPoint($month)];
            });

        foreach ($overviewPostings as $entry) {
            $posting = $entry['posting'];
            $month = CarbonImmutable::parse($posting->date)->format('Y-m');

            if (! isset($monthly[$month])) {
                continue;
            }

            $point = $monthly[$month];

            // Only postings with a resolved reporting amount aggregate. An
            // eligible-but-unresolved posting is counted as missing so the
            // month can be labeled partial instead of looking empty.
            if ($posting->amount === null) {
                if ($this->isConvertible($posting)) {
                    $point['missing']++;
                    $monthly[$month] = $point;
                }

                continue;
            }

            $key = $entry['bucket']->value;
            $point[$key] += $entry['bucket'] === Bucket::Income
                ? (float) $posting->amount
                : -(float) $posting->amount;
            $point['result'] = $this->monthlyResult($point);
            $monthly[$month] = $point;
        }

        $summaryMonthly = $monthly->filter(
            fn (array $point): bool => $point['month'] >= $start->format('Y-m')
                && $point['month'] <= $end->format('Y-m'),
        );

        $summary = $this->buildSummary($summaryMonthly);
        $summary['coverage'] = $this->coverage($postings);

        // Selectable months follow the chart's range, which includes the
        // in-progress preview month; the period average stays bounded by
        // the completed months only.
        $breakdownMonth = $this->resolveBreakdownMonth($request, $start, $chartEnd);

        return Inertia::render('dashboard', [
            'period' => [
                'selected' => $period,
                'months' => $months,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'options' => $periodOptions,
            ],
            'summary' => $summary,
            'monthly' => $monthly->values(),
            'balance' => $this->consolidatedBalance($request),
            'primaryCurrency' => $request->user()->reporting_currency,
            'breakdown' => Inertia::defer(fn (): array => $this->buildBreakdown(
                $overviewPostings,
                $start,
                $end,
                $months,
                $breakdownMonth,
            )),
        ]);
    }

    /**
     * A zeroed monthly point carrying one total per overview bucket.
     *
     * @return array<string, mixed>
     */
    private function emptyMonthlyPoint(CarbonImmutable $month): array
    {
        return [
            'month' => $month->format('Y-m'),
            'label' => $month->format('M y'),
            'income' => 0.0,
            'bills' => 0.0,
            'consumption' => 0.0,
            'investments' => 0.0,
            'savings' => 0.0,
            'uncategorized' => 0.0,
            'result' => 0.0,
            'missing' => 0,
        ];
    }

    /**
     * A posting can be reported once it has an account amount and currency —
     * those are the only rows that may count as "missing" coverage.
     */
    private function isConvertible(Posting $posting): bool
    {
        return $posting->original_amount !== null && $posting->original_currency !== null;
    }

    /**
     * Reporting-currency coverage over a posting collection: how many
     * postings are eligible for a reporting amount and how many have one.
     *
     * @param  Collection<int, Posting>  $postings
     * @return array{eligible: int, converted: int, missing: int}
     */
    private function coverage(Collection $postings): array
    {
        $eligible = $postings->filter(
            fn (Posting $posting): bool => $posting->amount !== null || $this->isConvertible($posting),
        );

        return [
            'eligible' => $eligible->count(),
            'converted' => $eligible->whereNotNull('amount')->count(),
            'missing' => $eligible->whereNull('amount')->count(),
        ];
    }

    /**
     * Net result for a month: income minus every outgoing bucket.
     *
     * @param  array<string, mixed>  $point
     */
    private function monthlyResult(array $point): float
    {
        return round(
            $point['income']
            - $point['bills']
            - $point['consumption']
            - $point['investments']
            - $point['savings']
            - $point['uncategorized'],
            2,
        );
    }

    /**
     * Rolls the monthly points up into the period summary. `in` is income,
     * `out` is the net total of every outgoing bucket (outflows positive,
     * refunds subtract), and `result` is their difference. Per-bucket totals
     * are kept for the frontend.
     *
     * @param  Collection<string, array<string, mixed>>  $summaryMonthly
     * @return array<string, float>
     */
    private function buildSummary(Collection $summaryMonthly): array
    {
        $income = round($summaryMonthly->sum('income'), 2);
        $bills = round($summaryMonthly->sum('bills'), 2);
        $consumption = round($summaryMonthly->sum('consumption'), 2);
        $investments = round($summaryMonthly->sum('investments'), 2);
        $savings = round($summaryMonthly->sum('savings'), 2);
        $uncategorized = round($summaryMonthly->sum('uncategorized'), 2);

        $out = round($bills + $consumption + $investments + $savings + $uncategorized, 2);

        return [
            'in' => $income,
            'out' => $out,
            'result' => round($income - $out, 2),
            'income' => $income,
            'bills' => $bills,
            'consumption' => $consumption,
            'investments' => $investments,
            'savings' => $savings,
            'uncategorized' => $uncategorized,
        ];
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
            ->whereNotNull('current_balance')
            ->get(['currency', 'current_balance']);

        $total = '0';
        $missing = 0;
        $used = false;
        $valuationDates = [];

        foreach ($accounts as $account) {
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
     * @param  Collection<int, string>  $periodOptions
     * @return array{0: string, 1: int, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function resolvePeriod(
        Request $request,
        Collection $periodOptions,
        CarbonImmutable $lastCompletedMonthEnd,
    ): array {
        $requested = (string) $request->query('period', '');

        // Back-compat: the legacy `months` param wins when `period` is absent.
        if ($requested === '' && $request->has('months')) {
            $months = min(max($request->integer('months', 12), 3), 36);
            $end = $lastCompletedMonthEnd;
            $start = $end->startOfMonth()->subMonthsNoOverflow($months - 1);

            return ['12m', $months, $start, $end];
        }

        if ($requested !== '' && $periodOptions->contains($requested) && $requested !== '12m') {
            $year = (int) $requested;
            $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
            $yearEnd = CarbonImmutable::create($year, 12, 31)->endOfDay();
            $end = $yearEnd->gt($lastCompletedMonthEnd) ? $lastCompletedMonthEnd : $yearEnd;

            if ($end->lt($start)) {
                $end = $start->endOfMonth();
            }

            $months = (int) $start->diffInMonths($end->startOfMonth()) + 1;

            return [$requested, $months, $start, $end];
        }

        // Default: last 12 completed months.
        $months = 12;
        $end = $lastCompletedMonthEnd;
        $start = $end->startOfMonth()->subMonthsNoOverflow($months - 1);

        return ['12m', $months, $start, $end];
    }

    private function resolveBreakdownMonth(
        Request $request,
        CarbonImmutable $start,
        CarbonImmutable $chartEnd,
    ): ?string {
        $requested = (string) $request->query('breakdownMonth', '');

        if ($requested === '' || preg_match('/^\d{4}-\d{2}$/', $requested) !== 1) {
            return null;
        }

        if ($requested < $start->format('Y-m') || $requested > $chartEnd->format('Y-m')) {
            return null;
        }

        return $requested;
    }

    /**
     * @param  Collection<int, array{posting: Posting, bucket: Bucket}>  $overviewPostings
     * @return array{month: ?string, months: int, categories: array<int, array<string, mixed>>}
     */
    private function buildBreakdown(
        Collection $overviewPostings,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $months,
        ?string $breakdownMonth,
    ): array {
        $divisor = $breakdownMonth !== null ? 1 : max(1, $months);

        // Every overview bucket contributes: income sits on the income side,
        // the outgoing buckets (bills/consumption/investments/savings and the
        // uncategorized group) on the expense side.
        $relevant = $overviewPostings->filter(function (array $entry) use ($start, $end, $breakdownMonth): bool {
            $month = CarbonImmutable::parse($entry['posting']->date)->format('Y-m');

            if ($breakdownMonth !== null) {
                return $month === $breakdownMonth;
            }

            return $month >= $start->format('Y-m') && $month <= $end->format('Y-m');
        });

        $categories = $relevant
            ->groupBy(fn (array $entry): string => $entry['bucket'] === Bucket::Income ? 'income' : 'expense')
            ->flatMap(function (Collection $group, string $type) use ($divisor): Collection {
                return $group
                    ->groupBy(fn (array $entry): string => (string) ($entry['posting']->categorizedSubcategory?->category?->id ?? 'uncategorized'))
                    ->map(function (Collection $entries) use ($type, $divisor): array {
                        $postings = $entries->map(fn (array $entry): Posting => $entry['posting']);
                        // Unresolved postings are visible via the missing
                        // count but excluded from every monetary figure.
                        $converted = $postings->whereNotNull('amount')->values();
                        $category = $postings->first()?->categorizedSubcategory?->category;
                        $total = (float) $converted->sum(fn (Posting $posting): float => (float) $posting->amount);

                        // A category can span buckets (fixed and variable
                        // subcategories); the row is colored by wherever most
                        // of its money lands.
                        $dominantBucket = $entries
                            ->groupBy(fn (array $entry): string => $entry['bucket']->value)
                            ->map(fn (Collection $items): float => abs((float) $items->sum(
                                fn (array $item): float => (float) $item['posting']->amount,
                            )))
                            ->sortDesc()
                            ->keys()
                            ->first();

                        $topSubcategories = $converted
                            ->groupBy(fn (Posting $posting): string => $posting->categorizedSubcategory?->name ?? 'Uncategorized')
                            ->map(fn (Collection $items, string $name): array => [
                                'name' => $name,
                                // Averaged over the same divisor as the category total so
                                // the tooltip's subcategory amounts sum toward it.
                                'amount' => round(abs((float) $items->sum(fn (Posting $posting): float => (float) $posting->amount)) / $divisor, 2),
                            ])
                            ->sortByDesc('amount')
                            ->values()
                            ->take(5);

                        $topPostings = $converted
                            ->sortByDesc(fn (Posting $posting): float => abs((float) $posting->amount))
                            ->take(5)
                            ->map(fn (Posting $posting): array => [
                                'description' => $posting->description,
                                'date' => CarbonImmutable::parse($posting->date)->toDateString(),
                                'amount' => round(abs((float) $posting->amount), 2),
                            ])
                            ->values();

                        return [
                            'id' => $category?->id,
                            'name' => $category?->name ?? 'Uncategorized',
                            'type' => $type,
                            'bucket' => $dominantBucket,
                            'total' => round(abs($total) / $divisor, 2),
                            'missing' => $postings->filter(
                                fn (Posting $posting): bool => $posting->amount === null && $this->isConvertible($posting),
                            )->count(),
                            'topSubcategories' => $topSubcategories->all(),
                            'topPostings' => $topPostings->all(),
                        ];
                    })
                    ->values();
            })
            ->sortByDesc('total')
            ->values();

        return [
            'month' => $breakdownMonth,
            'months' => $months,
            'categories' => $categories->all(),
        ];
    }
}
