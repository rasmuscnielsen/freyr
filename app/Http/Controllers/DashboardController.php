<?php

namespace App\Http\Controllers;

use App\Models\Posting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $months = min(max((int) $request->integer('months', 12), 3), 36);
        $latestDate = Posting::query()->forUser($user)->max('date');
        if ($latestDate === null) {
            $latestPostingDate = CarbonImmutable::now()->endOfMonth();
            $end = $latestPostingDate;
        } else {
            $latestPostingDate = CarbonImmutable::parse($latestDate);
            $end = $latestPostingDate->isSameMonth(CarbonImmutable::now())
                ? $latestPostingDate->subMonthNoOverflow()->endOfMonth()
                : $latestPostingDate->endOfMonth();
        }

        $start = $end->startOfMonth()->subMonthsNoOverflow($months - 1);
        $chartEnd = $latestPostingDate->endOfMonth();
        $chartMonths = $end->lt($chartEnd) ? $months + 1 : $months;

        $postings = Posting::query()
            ->forUser($user)
            ->with(['categorizedSubcategory.category'])
            ->whereBetween('date', [$start->toDateString(), $chartEnd->toDateString()])
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
            fn (array $bucket): bool => $bucket['month'] <= $end->format('Y-m'),
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
        $summary['average_result'] = round($summary['result'] / $months, 2);
        $summary['result_with_savings'] = round($summary['result'] - $summary['savings'], 2);

        $spendingCategories = $overviewPostings
            ->filter(fn (Posting $posting): bool => $posting->countsAsOverviewExpense())
            ->filter(fn (Posting $posting): bool => CarbonImmutable::parse($posting->date)->lte($end))
            ->groupBy(fn (Posting $posting): string => $posting->categorizedSubcategory?->category?->name ?? 'Uncategorized')
            ->map(function ($group, string $name) use ($months): array {
                $total = round((float) $group->sum(fn (Posting $posting): float => (float) $posting->amount), 2);

                return [
                    'name' => $name,
                    'amount' => round(abs(min(0, $total)) / $months, 2),
                    'total_amount' => round(abs(min(0, $total)), 2),
                    'posting_count' => $group->count(),
                    'category_id' => $group->first()?->categorizedSubcategory?->category?->id,
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->take(10);

        return Inertia::render('dashboard', [
            'period' => [
                'months' => $months,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'options' => [3, 6, 12, 24, 36],
            ],
            'summary' => $summary,
            'monthly' => $monthly->values(),
            'spendingCategories' => $spendingCategories,
        ]);
    }
}
