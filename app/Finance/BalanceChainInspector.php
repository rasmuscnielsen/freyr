<?php

namespace App\Finance;

use App\Models\BankAccount;
use App\Models\Posting;
use Illuminate\Support\Collection;

/**
 * Locates where an account's running-balance chain stops adding up.
 *
 * Postings are walked day by day in (date, id) order. The first stored balance
 * anchors the expectation; from there each day must close at
 * `previous close + sum(day amounts)`. Days that do close are clean even when
 * rows were stored out of intra-day order (the bank's true order is unknown).
 * Days that do not close are classified:
 *
 * - `possible_duplicate`: a row duplicated within the day explains the delta.
 * - `gap`: the day's rows agree with each other but the day opened on the
 *   wrong foot — a transaction of ~delta is missing (or extra) before it.
 * - `suspect_day`: the day's own rows contradict each other; the whole day is
 *   returned as the suspect set.
 * - `tail_lag`: the newest running balance disagrees with the bank-reported
 *   account balance — often just pending or not-yet-synced transactions.
 *
 * The result is honest about its limits: it names positions and amounts, not
 * transactions, and same-day breaks may only narrow down to the day.
 */
class BalanceChainInspector
{
    private const float TOLERANCE = 0.005;

    private const int MAX_BREAKS = 20;

    /**
     * @return array{
     *     is_consistent: bool,
     *     total_delta: ?string,
     *     closing_balance: ?string,
     *     checked_postings: int,
     *     breaks: list<array{kind: string, date: ?string, delta: ?string, postings: list<array<string, mixed>>}>
     * }
     */
    public function inspect(BankAccount $bankAccount): array
    {
        $postings = $bankAccount->postings()
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'date', 'description', 'amount', 'balance']);

        $breaks = [];
        $running = null;

        foreach ($postings->groupBy(fn (Posting $posting): string => $posting->date->toDateString()) as $date => $dayPostings) {
            $checkpoints = $dayPostings->whereNotNull('balance');

            if ($running === null) {
                // No expectation yet: the first day with a stored balance
                // anchors the walk, everything before it is uncheckable.
                $running = $checkpoints->isNotEmpty() ? (float) $checkpoints->last()->balance : null;

                continue;
            }

            $expectedClose = $running + (float) $dayPostings->sum('amount');

            if ($checkpoints->isEmpty()) {
                $running = $expectedClose;

                continue;
            }

            // The bank's intra-day order is unknown, so a day is clean when any
            // of its stored balances lands on the expected close.
            $closesAsExpected = $checkpoints->contains(
                fn (Posting $posting): bool => $this->matches((float) $posting->balance, $expectedClose),
            );

            if ($closesAsExpected) {
                $running = $expectedClose;

                continue;
            }

            $reportedClose = (float) $checkpoints->last()->balance;
            if (count($breaks) < self::MAX_BREAKS) {
                $breaks[] = $this->classifyBreak($date, $dayPostings, $running, $expectedClose, $reportedClose);
            }

            // Resync on the bank's figure so later days are judged on their own.
            $running = $reportedClose;
        }

        $totalDelta = null;

        if ($running !== null && $bankAccount->current_balance !== null) {
            $totalDelta = (float) $bankAccount->current_balance - $running;

            if (! $this->matches($totalDelta, 0.0)) {
                $breaks[] = [
                    'kind' => 'tail_lag',
                    'date' => $postings->last()?->date->toDateString(),
                    'delta' => $this->decimal($totalDelta),
                    'postings' => [],
                ];
            }
        }

        return [
            'is_consistent' => $breaks === [],
            'total_delta' => $totalDelta === null ? null : $this->decimal($totalDelta),
            'closing_balance' => $running === null ? null : $this->decimal($running),
            'checked_postings' => $postings->count(),
            'breaks' => $breaks,
        ];
    }

    /**
     * @param  Collection<int, Posting>  $dayPostings
     * @return array{kind: string, date: string, delta: string, postings: list<array<string, mixed>>}
     */
    private function classifyBreak(string $date, Collection $dayPostings, float $dayOpen, float $expectedClose, float $reportedClose): array
    {
        $delta = $reportedClose - $expectedClose;

        $duplicates = $this->duplicatesExplaining($dayPostings, $delta);

        if ($duplicates !== null) {
            return [
                'kind' => 'possible_duplicate',
                'date' => $date,
                'delta' => $this->decimal($delta),
                'postings' => $this->postingPayload($duplicates),
            ];
        }

        // If the day's checkpoints agree with each other (each step matches the
        // amounts between them), the rows are fine and the money went missing
        // before the day opened; otherwise the day itself is suspect.
        $internallyConsistent = $this->checkpointsChainInternally($dayPostings);

        return [
            'kind' => $internallyConsistent ? 'gap' : 'suspect_day',
            'date' => $date,
            'delta' => $this->decimal($delta),
            'postings' => $this->postingPayload($dayPostings),
        ];
    }

    /**
     * A duplicated (amount, description) pair explains the delta when the
     * chain carries exactly one copy too many: expected − reported == amount.
     *
     * @param  Collection<int, Posting>  $dayPostings
     * @return ?Collection<int, Posting>
     */
    private function duplicatesExplaining(Collection $dayPostings, float $delta): ?Collection
    {
        $duplicated = $dayPostings
            ->groupBy(fn (Posting $posting): string => $posting->amount.'|'.$posting->description)
            ->filter(fn (Collection $group): bool => $group->count() >= 2)
            ->first(fn (Collection $group): bool => $this->matches((float) $group->first()->amount, -$delta));

        return $duplicated?->values();
    }

    /**
     * @param  Collection<int, Posting>  $dayPostings
     */
    private function checkpointsChainInternally(Collection $dayPostings): bool
    {
        $previousBalance = null;
        $amountsSinceCheckpoint = 0.0;

        foreach ($dayPostings as $posting) {
            $amountsSinceCheckpoint += (float) $posting->amount;

            if ($posting->balance === null) {
                continue;
            }

            $balance = (float) $posting->balance;

            if ($previousBalance !== null && ! $this->matches($balance, $previousBalance + $amountsSinceCheckpoint)) {
                return false;
            }

            $previousBalance = $balance;
            $amountsSinceCheckpoint = 0.0;
        }

        return true;
    }

    /**
     * @param  Collection<int, Posting>  $postings
     * @return list<array<string, mixed>>
     */
    private function postingPayload(Collection $postings): array
    {
        return $postings
            ->map(fn (Posting $posting): array => [
                'id' => $posting->id,
                'date' => $posting->date->toDateString(),
                'description' => $posting->description,
                'amount' => (float) $posting->amount,
                'balance' => $posting->balance === null ? null : (float) $posting->balance,
            ])
            ->values()
            ->all();
    }

    private function matches(float $left, float $right): bool
    {
        return abs($left - $right) <= self::TOLERANCE;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
