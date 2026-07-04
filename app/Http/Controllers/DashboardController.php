<?php

namespace App\Http\Controllers;

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

        $overviewPostings = $postings->filter(
            fn (Posting $posting): bool => $posting->countsAsOverviewIncome()
                || $posting->countsAsOverviewExpense()
                || $posting->countsAsOverviewSaving(),
        );

        $monthly = collect(range(0, $chartMonths - 1))
            ->mapWithKeys(function (int $offset) use ($start): array {
                $month = $start->addMonths($offset);

                return [
                    $month->format('Y-m') => [
                        'month' => $month->format('Y-m'),
                        'label' => $month->format('M y'),
                        'income' => 0.0,
                        'bills' => 0.0,
                        'consumption' => 0.0,
                        'expenses' => 0.0,
                        'savings' => 0.0,
                        'result' => 0.0,
                    ],
                ];
            });

        foreach ($overviewPostings as $posting) {
            $month = CarbonImmutable::parse($posting->date)->format('Y-m');
            $bucket = $monthly[$month] ?? null;

            if ($bucket === null) {
                continue;
            }

            $amount = (float) $posting->amount;

            if ($posting->countsAsOverviewIncome()) {
                $bucket['income'] += $amount;
            } elseif ($posting->countsAsOverviewSaving()) {
                $bucket['savings'] += abs($amount);
            } elseif ($posting->countsAsOverviewExpense()) {
                if ($posting->isFixedOverviewExpense()) {
                    $bucket['bills'] += $amount;
                } else {
                    $bucket['consumption'] += $amount;
                }
            }

            $bucket['expenses'] = abs(min(0, $bucket['bills'] + $bucket['consumption']));
            $bucket['result'] = $bucket['income'] + $bucket['bills'] + $bucket['consumption'];
            $monthly[$month] = $bucket;
        }

        $summaryMonthly = $monthly->filter(
            fn (array $bucket): bool => $bucket['month'] >= $start->format('Y-m')
                && $bucket['month'] <= $end->format('Y-m'),
        );
        $bills = round($summaryMonthly->sum('bills'), 2);
        $consumption = round($summaryMonthly->sum('consumption'), 2);
        $summary = [
            'income' => round($summaryMonthly->sum('income'), 2),
            'bills' => $bills,
            'consumption' => $consumption,
            'expenses' => round(abs(min(0, $bills + $consumption)), 2),
            'savings' => round($summaryMonthly->sum('savings'), 2),
            'result' => round($summaryMonthly->sum('result'), 2),
        ];
        $summary['average_result'] = round($summary['result'] / max(1, $months), 2);
        $summary['result_with_savings'] = round($summary['result'] - $summary['savings'], 2);

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
     * @param  Collection<int, Posting>  $overviewPostings
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

        $relevant = $overviewPostings->filter(function (Posting $posting) use ($start, $end, $breakdownMonth): bool {
            if (! ($posting->countsAsOverviewIncome() || $posting->countsAsOverviewExpense())) {
                return false;
            }

            $month = CarbonImmutable::parse($posting->date)->format('Y-m');

            if ($breakdownMonth !== null) {
                return $month === $breakdownMonth;
            }

            return $month >= $start->format('Y-m') && $month <= $end->format('Y-m');
        });

        $categories = $relevant
            ->groupBy(fn (Posting $posting): string => $posting->countsAsOverviewIncome() ? 'income' : 'expense')
            ->flatMap(function (Collection $group, string $type) use ($divisor): Collection {
                return $group
                    ->groupBy(fn (Posting $posting): string => (string) ($posting->categorizedSubcategory?->category?->id ?? 'uncategorized'))
                    ->map(function (Collection $postings) use ($type, $divisor): array {
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
