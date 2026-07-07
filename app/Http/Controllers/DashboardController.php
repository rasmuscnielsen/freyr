<?php

namespace App\Http\Controllers;

use App\Enums\Bucket;
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

        $breakdownMonth = $this->resolveBreakdownMonth($request, $start, $end);

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
            'balance' => $this->totalBalance($request),
            'primaryCurrency' => $this->primaryCurrency($request),
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
     * Total balance across the user's active accounts in the primary currency,
     * preferring the last booked balance and falling back to the last available
     * one. Null when no active account matches the primary currency.
     */
    private function totalBalance(Request $request): ?float
    {
        $primaryCurrency = $this->primaryCurrency($request);

        if ($primaryCurrency === null) {
            return null;
        }

        $accounts = BankAccount::query()
            ->whereBelongsTo($request->user())
            ->where('is_active', true)
            ->where('currency', $primaryCurrency)
            ->get(['last_booked_balance', 'last_available_balance']);

        if ($accounts->isEmpty()) {
            return null;
        }

        return round($accounts->sum(function (BankAccount $account): float {
            $balance = $account->last_booked_balance ?? $account->last_available_balance;

            return $balance === null ? 0.0 : (float) $balance;
        }), 2);
    }

    /**
     * The currency the dashboard aggregates are displayed in: the most common
     * currency across the user's active accounts. Aggregating across mixed
     * currencies is not supported yet.
     */
    private function primaryCurrency(Request $request): ?string
    {
        return BankAccount::query()
            ->whereBelongsTo($request->user())
            ->where('is_active', true)
            ->select('currency')
            ->selectRaw('count(*) as account_count')
            ->groupBy('currency')
            ->orderByDesc('account_count')
            ->value('currency');
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
        CarbonImmutable $end,
    ): ?string {
        $requested = (string) $request->query('breakdownMonth', '');

        if ($requested === '' || preg_match('/^\d{4}-\d{2}$/', $requested) !== 1) {
            return null;
        }

        if ($requested < $start->format('Y-m') || $requested > $end->format('Y-m')) {
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
                        $category = $postings->first()?->categorizedSubcategory?->category;
                        $total = (float) $postings->sum(fn (Posting $posting): float => (float) $posting->amount);

                        $topSubcategories = $postings
                            ->groupBy(fn (Posting $posting): string => $posting->categorizedSubcategory?->name ?? 'Uncategorized')
                            ->map(fn (Collection $items, string $name): array => [
                                'name' => $name,
                                'amount' => round(abs((float) $items->sum(fn (Posting $posting): float => (float) $posting->amount)), 2),
                            ])
                            ->sortByDesc('amount')
                            ->values()
                            ->take(5);

                        $topPostings = $postings
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
                            'total' => round(abs($total) / $divisor, 2),
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
