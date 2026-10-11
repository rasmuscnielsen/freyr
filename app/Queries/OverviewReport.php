<?php

namespace App\Queries;

use App\Enums\Bucket;
use App\Enums\CategoryType;
use App\Enums\Interval;
use App\Models\Posting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates a user's postings into the report behind the dashboard: a
 * series of per-interval bucket totals, a windowed summary with
 * reporting-currency coverage, and the per-category breakdown. Shared by
 * the dashboard page and the /api/reports endpoints.
 *
 * Postings resolve to overview buckets in SQL — the CASE mirrors
 * {@see Posting::overviewBucket()} so the two stay interchangeable —
 * and the heavy lifting is grouped server-side: the series and summary
 * read a few hundred grouped rows instead of iterating every posting.
 * Postings without a resolved reporting amount count as `missing` so
 * partial periods stay honest.
 *
 * @phpstan-type OverviewPoint array{period: string, label: string, complete: bool, income: float, bills: float, living_costs: float, lifestyle: float, other: float, investments: float, savings: float, uncategorized: float, result: float, missing: int}
 * @phpstan-type OverviewCategory array{id: int|null, name: string, type: string, bucket: string|null, total: float, missing: int, subcategories: array<int, array{id: int|null, name: string, amount: float}>, topPostings: array<int, array{description: string|null, date: string, amount: float}>, series?: array<int, array{period: string, total: float}>}
 */
final class OverviewReport
{
    /** @var Collection<int, object>|null */
    private ?Collection $stats = null;

    /** @var Collection<int, array{row: object, bucket: Bucket, date: CarbonImmutable, key: string}>|null */
    private ?Collection $rows = null;

    /** @var Collection<string, OverviewPoint>|null */
    private ?Collection $points = null;

    private function __construct(
        private readonly User $user,
        private readonly CarbonImmutable $start,
        private readonly CarbonImmutable $end,
        private readonly CarbonImmutable $through,
        private readonly Interval $interval,
    ) {}

    /**
     * @param  CarbonImmutable  $start  Window start (inclusive); bounds the summary, averages and completeness
     * @param  CarbonImmutable  $end  Window end (inclusive); typically the last completed month for periods
     * @param  Interval  $interval  Aggregation granularity
     * @param  CarbonImmutable|null  $through  Series bound — lets the series extend past $end so an in-progress period can render as a preview
     */
    public static function for(
        User $user,
        CarbonImmutable $start,
        CarbonImmutable $end,
        Interval $interval = Interval::Month,
        ?CarbonImmutable $through = null,
    ): self {
        return new self($user, $start->startOfDay(), $end->endOfDay(), ($through ?? $end)->endOfDay(), $interval);
    }

    public function interval(): Interval
    {
        return $this->interval;
    }

    public function start(): CarbonImmutable
    {
        return $this->start;
    }

    public function end(): CarbonImmutable
    {
        return $this->end;
    }

    /**
     * The number of interval units inside the window — the divisor that turns
     * window totals into per-period averages: whole months for monthly
     * reports, fractional years (months / 12) for yearly ones.
     */
    public function units(): float
    {
        $months = (int) $this->start->diffInMonths($this->end->startOfMonth()) + 1;

        return $this->interval === Interval::Year ? $months / 12 : $months;
    }

    /**
     * Whether a focus key selects a sub-period inside the reported series:
     * 'YYYY-MM' on a monthly report, 'YYYY' on a yearly one.
     */
    public function acceptsFocus(string $focus): bool
    {
        return $focus !== ''
            && $this->interval->matchesFocus($focus)
            && $focus >= $this->interval->key($this->start)
            && $focus <= $this->interval->key($this->through);
    }

    /**
     * Per-interval bucket totals from $start through $through. A point is
     * `complete` when its whole interval sits inside the window — a point
     * clipped by either bound or still in progress renders as a partial
     * preview.
     *
     * @return Collection<int, OverviewPoint>
     */
    public function series(): Collection
    {
        return ($this->points ??= $this->buildSeries())->values();
    }

    /**
     * Window totals: income in `in`, the net outgoing total in `out`, their
     * difference in `result`, the per-bucket totals, and the coverage of
     * postings with a resolved reporting amount.
     *
     * @return array{in: float, out: float, result: float, income: float, bills: float, living_costs: float, lifestyle: float, other: float, investments: float, savings: float, uncategorized: float, coverage: array{eligible: int, converted: int, missing: int}}
     */
    public function summary(): array
    {
        $totals = [];
        $coverage = ['eligible' => 0, 'converted' => 0, 'missing' => 0];

        foreach (Bucket::cases() as $bucket) {
            $totals[$bucket->value] = 0.0;
        }

        foreach ($this->stats() as $row) {
            if ((int) $row->in_window !== 1 || $row->bucket === null) {
                continue;
            }

            $coverage['eligible'] += (int) $row->count;
            $coverage['missing'] += (int) $row->missing;

            $totals[$row->bucket] += $row->bucket === Bucket::Income->value
                ? (float) $row->total
                : -(float) $row->total;
        }

        $coverage['converted'] = $coverage['eligible'] - $coverage['missing'];

        $income = round($totals[Bucket::Income->value], 2);
        $bills = round($totals[Bucket::Bills->value], 2);
        $livingCosts = round($totals[Bucket::LivingCosts->value], 2);
        $lifestyle = round($totals[Bucket::Lifestyle->value], 2);
        $other = round($totals[Bucket::Other->value], 2);
        $investments = round($totals[Bucket::Investments->value], 2);
        $savings = round($totals[Bucket::Savings->value], 2);
        $uncategorized = round($totals[Bucket::Uncategorized->value], 2);
        $out = round($bills + $livingCosts + $lifestyle + $other + $investments + $savings + $uncategorized, 2);

        return [
            'in' => $income,
            'out' => $out,
            'result' => round($income - $out, 2),
            'income' => $income,
            'bills' => $bills,
            'living_costs' => $livingCosts,
            'lifestyle' => $lifestyle,
            'other' => $other,
            'investments' => $investments,
            'savings' => $savings,
            'uncategorized' => $uncategorized,
            'coverage' => $coverage,
        ];
    }

    /**
     * Per-category totals over the window (or the focused sub-period), split
     * income/expense, colored by wherever most of the category's money lands.
     * Totals are raw sums — callers turn them into per-period averages via
     * {@see units()}.
     *
     * @return array<int, OverviewCategory>
     */
    public function categories(?string $focus = null): array
    {
        $relevant = $this->rows()
            ->filter(fn (array $entry): bool => $focus !== null
                ? $entry['key'] === $focus
                : $entry['date']->betweenIncluded($this->start, $this->end));

        return $relevant
            ->groupBy(fn (array $entry): string => $entry['bucket'] === Bucket::Income ? 'income' : 'expense')
            ->flatMap(function (Collection $group, string $type): Collection {
                return $group
                    ->groupBy(fn (array $entry): string => (string) ($entry['row']->category_id ?? 'uncategorized'))
                    ->map(function (Collection $entries) use ($type): array {
                        $rows = $entries->map(fn (array $entry): object => $entry['row']);
                        // Unresolved postings are visible via the missing
                        // count but excluded from every monetary figure.
                        $converted = $rows->whereNotNull('amount')->values();
                        $total = (float) $converted->sum(fn (object $row): float => (float) $row->amount);

                        // A category can span buckets (fixed and variable
                        // subcategories); the row is colored by wherever most
                        // of its money lands.
                        $dominantBucket = $entries
                            ->groupBy(fn (array $entry): string => $entry['bucket']->value)
                            ->map(fn (Collection $items): float => abs((float) $items->sum(
                                fn (array $item): float => (float) $item['row']->amount,
                            )))
                            ->sortDesc()
                            ->keys()
                            ->first();

                        $subcategories = $converted
                            ->groupBy(fn (object $row): string => (string) ($row->subcategory_id ?? 'uncategorized'))
                            ->map(fn (Collection $items): array => [
                                'id' => $items->first()?->subcategory_id,
                                'name' => $items->first()?->subcategory_name ?? 'Uncategorized',
                                'amount' => round(abs((float) $items->sum(fn (object $row): float => (float) $row->amount)), 2),
                            ])
                            ->sortByDesc('amount')
                            ->values();

                        $topPostings = $converted
                            ->sortByDesc(fn (object $row): float => abs((float) $row->amount))
                            ->take(5)
                            ->map(fn (object $row): array => [
                                'description' => $row->description,
                                'date' => CarbonImmutable::parse($row->date)->toDateString(),
                                'amount' => round(abs((float) $row->amount), 2),
                            ])
                            ->values();

                        return [
                            'id' => $rows->first()?->category_id,
                            'name' => $rows->first()?->category_name ?? 'Uncategorized',
                            'type' => $type,
                            'bucket' => $dominantBucket,
                            'total' => round(abs($total), 2),
                            'missing' => $rows->whereNull('amount')->count(),
                            'subcategories' => $subcategories->all(),
                            'topPostings' => $topPostings->all(),
                        ];
                    })
                    ->values();
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Per-category totals split by interval period, keyed by `{type}:{id}`
     * — the same income/expense split {@see categories()} makes, so an
     * uncategorized (or cross-bucket) category never nets opposite flows
     * into one series. Only periods with postings appear, in chronological
     * order.
     *
     * @return array<string, array<int, array{period: string, total: float}>>
     */
    public function categorySeries(): array
    {
        return $this->rows()
            ->filter(fn (array $entry): bool => $entry['date']->betweenIncluded($this->start, $this->end)
                && $entry['row']->amount !== null)
            ->groupBy(fn (array $entry): string => ($entry['bucket'] === Bucket::Income ? 'income' : 'expense')
                .':'.($entry['row']->category_id ?? 'uncategorized'))
            ->map(fn (Collection $entries): array => $entries
                ->groupBy(fn (array $entry): string => $entry['key'])
                ->map(fn (Collection $periodEntries, string $period): array => [
                    'period' => $period,
                    'total' => round(abs((float) $periodEntries->sum(fn (array $entry): float => (float) $entry['row']->amount)), 2),
                ])
                ->sortKeys()
                ->values()
                ->all())
            ->all();
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function buildSeries(): Collection
    {
        $points = collect();
        $cursor = $this->interval->startOf($this->start);

        while ($cursor->lte($this->through)) {
            $key = $this->interval->key($cursor);
            $points[$key] = [
                'period' => $key,
                'label' => $this->interval->label($cursor),
                'complete' => $cursor->gte($this->start) && $this->interval->endOf($cursor)->lte($this->end),
                'income' => 0.0,
                'bills' => 0.0,
                'living_costs' => 0.0,
                'lifestyle' => 0.0,
                'other' => 0.0,
                'investments' => 0.0,
                'savings' => 0.0,
                'uncategorized' => 0.0,
                'result' => 0.0,
                'missing' => 0,
            ];
            $cursor = $this->interval === Interval::Month ? $cursor->addMonth() : $cursor->addYear();
        }

        foreach ($this->stats() as $row) {
            if ($row->bucket === null || ! isset($points[$row->period])) {
                continue;
            }

            $point = $points[$row->period];
            $point['missing'] += (int) $row->missing;
            $point[$row->bucket] += $row->bucket === Bucket::Income->value
                ? (float) $row->total
                : -(float) $row->total;
            $points[$row->period] = $point;
        }

        return $points->map(fn (array $point): array => [
            ...$point,
            // Net result for the period: income minus every outgoing bucket.
            'result' => round(
                $point['income']
                - $point['bills']
                - $point['living_costs']
                - $point['lifestyle']
                - $point['other']
                - $point['investments']
                - $point['savings']
                - $point['uncategorized'],
                2,
            ),
        ]);
    }

    /**
     * Posting-level aggregates grouped by (period, in-window, bucket): the
     * database does the bucketing and summing, so this returns at most a
     * few hundred rows however large the posting history grows. One query
     * feeds both the series (all rows) and the summary (in-window rows).
     *
     * @return Collection<int, object>
     */
    private function stats(): Collection
    {
        return $this->stats ??= $this->bucketQuery()
            ->selectRaw("{$this->periodExpression()} as period")
            ->selectRaw("{$this->bucketExpression()} as bucket")
            ->selectRaw('CASE WHEN postings.date BETWEEN ? AND ? THEN 1 ELSE 0 END as in_window', [
                $this->start->toDateString(),
                $this->end->toDateString(),
            ])
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(postings.amount) as total')
            ->selectRaw('SUM(CASE WHEN postings.amount IS NULL THEN 1 ELSE 0 END) as missing')
            ->groupByRaw('1, 2, 3')
            ->get();
    }

    /**
     * Posting-level detail rows with their resolved bucket — plain rows off
     * a join rather than hydrated models, only what the category breakdown
     * reads (descriptions, amounts, category/subcategory names).
     *
     * @return Collection<int, array{row: object, bucket: Bucket, date: CarbonImmutable, key: string}>
     */
    private function rows(): Collection
    {
        return $this->rows ??= $this->bucketQuery()
            ->select([
                'postings.date',
                'postings.description',
                'postings.amount',
                'subcategories.id as subcategory_id',
                'subcategories.name as subcategory_name',
                'categories.id as category_id',
                'categories.name as category_name',
            ])
            ->selectRaw("{$this->bucketExpression()} as bucket")
            ->get()
            ->whereNotNull('bucket')
            ->map(function (object $row): array {
                // Date and interval key are computed once here — the passes
                // above reuse them instead of re-parsing per posting.
                $date = CarbonImmutable::parse($row->date);

                return [
                    'row' => $row,
                    'bucket' => Bucket::from($row->bucket),
                    'date' => $date,
                    'key' => $this->interval->key($date),
                ];
            })
            ->filter(fn (array $entry): bool => $entry['bucket'] instanceof Bucket)
            ->values();
    }

    /**
     * The shared query: a user's postings between $start and $through joined
     * to their categorization so buckets resolve in SQL.
     */
    private function bucketQuery(): Builder
    {
        return DB::table('postings')
            ->leftJoin('subcategories', 'subcategories.id', '=', 'postings.categorized_subcategory_id')
            ->leftJoin('categories', 'categories.id', '=', 'subcategories.category_id')
            ->where('postings.user_id', $this->user->id)
            ->whereBetween('postings.date', [$this->start->toDateString(), $this->through->toDateString()]);
    }

    /**
     * The SQL mirror of {@see Posting::overviewBucket()}: extraordinary
     * postings and {@see CategoryType::Exclude} categories land
     * NULL and are filtered out; uncategorized postings split on their sign.
     */
    private function bucketExpression(): string
    {
        return <<<'SQL'
            CASE
                WHEN postings.is_extraordinary THEN NULL
                WHEN categories.id IS NULL AND COALESCE(postings.original_amount, postings.amount, 0) >= 0 THEN 'income'
                WHEN categories.id IS NULL THEN 'uncategorized'
                WHEN categories.type = 'Income' THEN 'income'
                WHEN categories.type = 'Expense' AND subcategories.expense_group = 'Bills' THEN 'bills'
                WHEN categories.type = 'Expense' AND subcategories.expense_group = 'Living costs' THEN 'living_costs'
                WHEN categories.type = 'Expense' AND subcategories.expense_group = 'Lifestyle' THEN 'lifestyle'
                WHEN categories.type = 'Expense' THEN 'other'
                WHEN categories.type = 'Investment' THEN 'investments'
                WHEN categories.type = 'Saving' THEN 'savings'
                ELSE NULL
            END
        SQL;
    }

    /**
     * The series key expression per driver — sqlite/mysql need function-based
     * period keys, pgsql uses to_char. Grouping happens on the SQL side.
     */
    private function periodExpression(): string
    {
        $driver = DB::connection()->getDriverName();
        $format = $this->interval === Interval::Month ? '%Y-%m' : '%Y';

        return match ($driver) {
            'pgsql' => sprintf("to_char(postings.date, '%s')", $this->interval === Interval::Month ? 'YYYY-MM' : 'YYYY'),
            'sqlite' => "strftime('{$format}', postings.date)",
            'sqlsrv' => "FORMAT(postings.date, '".($this->interval === Interval::Month ? 'yyyy-MM' : 'yyyy')."')",
            default => "DATE_FORMAT(postings.date, '{$format}')",
        };
    }
}
