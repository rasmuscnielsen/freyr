<?php

namespace App\Queries;

use App\Enums\Bucket;
use App\Enums\Interval;
use App\Models\Posting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Aggregates a user's postings into the report behind the dashboard: a
 * series of per-interval bucket totals, a windowed summary with
 * reporting-currency coverage, and the per-category breakdown. Shared by
 * the dashboard page and the /api/reports endpoints.
 *
 * Postings resolve to overview buckets via {@see Posting::overviewBucket()};
 * amounts are reporting-currency figures, with unresolved postings counted
 * as `missing` so partial periods stay honest.
 */
final class OverviewReport
{
    /** @var Collection<int, array{posting: Posting, bucket: Bucket}>|null */
    private ?Collection $overviewPostings = null;

    /** @var Collection<string, array<string, mixed>>|null */
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
     * @return Collection<int, array<string, mixed>>
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
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $windowed = $this->windowedPostings();

        $totals = [];
        foreach (Bucket::cases() as $bucket) {
            $totals[$bucket->value] = 0.0;
        }

        foreach ($windowed as $entry) {
            $amount = $entry['posting']->amount;

            if ($amount === null) {
                continue;
            }

            $bucket = $entry['bucket'];
            $totals[$bucket->value] += $bucket === Bucket::Income
                ? (float) $amount
                : -(float) $amount;
        }

        $income = round($totals[Bucket::Income->value], 2);
        $bills = round($totals[Bucket::Bills->value], 2);
        $consumption = round($totals[Bucket::Consumption->value], 2);
        $investments = round($totals[Bucket::Investments->value], 2);
        $savings = round($totals[Bucket::Savings->value], 2);
        $uncategorized = round($totals[Bucket::Uncategorized->value], 2);
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
            'coverage' => $this->coverage($windowed),
        ];
    }

    /**
     * Per-category totals over the window (or the focused sub-period), split
     * income/expense, colored by wherever most of the category's money lands.
     * Totals are raw sums — callers turn them into per-period averages via
     * {@see units()}.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(?string $focus = null): array
    {
        $relevant = $this->overviewPostings()
            ->filter(fn (array $entry): bool => $focus !== null
                ? $this->interval->key(CarbonImmutable::parse($entry['posting']->date)) === $focus
                : CarbonImmutable::parse($entry['posting']->date)->betweenIncluded($this->start, $this->end));

        return $relevant
            ->groupBy(fn (array $entry): string => $entry['bucket'] === Bucket::Income ? 'income' : 'expense')
            ->flatMap(function (Collection $group, string $type): Collection {
                return $group
                    ->groupBy(fn (array $entry): string => (string) ($entry['posting']->categorizedSubcategory?->category?->id ?? 'uncategorized'))
                    ->map(function (Collection $entries) use ($type): array {
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

                        $subcategories = $converted
                            ->groupBy(fn (Posting $posting): string => (string) ($posting->categorizedSubcategory?->id ?? 'uncategorized'))
                            ->map(fn (Collection $items): array => [
                                'id' => $items->first()?->categorizedSubcategory?->id,
                                'name' => $items->first()?->categorizedSubcategory?->name ?? 'Uncategorized',
                                'amount' => round(abs((float) $items->sum(fn (Posting $posting): float => (float) $posting->amount)), 2),
                            ])
                            ->sortByDesc('amount')
                            ->values();

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
                            'total' => round(abs($total), 2),
                            'missing' => $postings->whereNull('amount')->count(),
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
     * Per-category totals split by interval period, keyed by category id
     * ('uncategorized' for postings without one). Only periods with postings
     * appear, in chronological order.
     *
     * @return array<string, array<int, array{period: string, total: float}>>
     */
    public function categorySeries(): array
    {
        return $this->windowedPostings()
            ->filter(fn (array $entry): bool => $entry['posting']->amount !== null)
            ->groupBy(fn (array $entry): string => (string) ($entry['posting']->categorizedSubcategory?->category?->id ?? 'uncategorized'))
            ->map(fn (Collection $entries): array => $entries
                ->groupBy(fn (array $entry): string => $this->interval->key(CarbonImmutable::parse($entry['posting']->date)))
                ->map(fn (Collection $periodEntries, string $period): array => [
                    'period' => $period,
                    'total' => round(abs((float) $periodEntries->sum(fn (array $entry): float => (float) $entry['posting']->amount)), 2),
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
                'consumption' => 0.0,
                'investments' => 0.0,
                'savings' => 0.0,
                'uncategorized' => 0.0,
                'result' => 0.0,
                'missing' => 0,
            ];
            $cursor = $this->interval === Interval::Month ? $cursor->addMonth() : $cursor->addYear();
        }

        foreach ($this->overviewPostings() as $entry) {
            $key = $this->interval->key(CarbonImmutable::parse($entry['posting']->date));

            if (! isset($points[$key])) {
                continue;
            }

            $point = $points[$key];

            // Only postings with a resolved reporting amount aggregate. An
            // eligible-but-unresolved posting is counted as missing so the
            // period can be labeled partial instead of looking empty.
            if ($entry['posting']->amount === null) {
                $point['missing']++;
                $points[$key] = $point;

                continue;
            }

            $bucket = $entry['bucket'];
            $point[$bucket->value] += $bucket === Bucket::Income
                ? (float) $entry['posting']->amount
                : -(float) $entry['posting']->amount;
            $points[$key] = $point;
        }

        return $points->map(fn (array $point): array => [
            ...$point,
            // Net result for the period: income minus every outgoing bucket.
            'result' => round(
                $point['income']
                - $point['bills']
                - $point['consumption']
                - $point['investments']
                - $point['savings']
                - $point['uncategorized'],
                2,
            ),
        ]);
    }

    /**
     * Every posting that lands in an overview bucket, indexed alongside its
     * resolved bucket so the mapping is computed once.
     *
     * @return Collection<int, array{posting: Posting, bucket: Bucket}>
     */
    private function overviewPostings(): Collection
    {
        return $this->overviewPostings ??= Posting::query()
            ->forUser($this->user)
            ->with(['categorizedSubcategory.category'])
            ->whereBetween('date', [$this->start->toDateString(), $this->through->toDateString()])
            ->get()
            ->map(fn (Posting $posting): array => ['posting' => $posting, 'bucket' => $posting->overviewBucket()])
            ->filter(fn (array $entry): bool => $entry['bucket'] instanceof Bucket)
            ->values();
    }

    /**
     * @return Collection<int, array{posting: Posting, bucket: Bucket}>
     */
    private function windowedPostings(): Collection
    {
        return $this->overviewPostings()
            ->filter(fn (array $entry): bool => CarbonImmutable::parse($entry['posting']->date)->betweenIncluded($this->start, $this->end))
            ->values();
    }

    /**
     * @param  Collection<int, array{posting: Posting, bucket: Bucket}>  $postings
     * @return array{eligible: int, converted: int, missing: int}
     */
    private function coverage(Collection $postings): array
    {
        return [
            'eligible' => $postings->count(),
            'converted' => $postings->filter(fn (array $entry): bool => $entry['posting']->amount !== null)->count(),
            'missing' => $postings->filter(fn (array $entry): bool => $entry['posting']->amount === null)->count(),
        ];
    }
}
