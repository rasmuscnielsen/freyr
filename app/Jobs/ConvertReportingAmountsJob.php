<?php

namespace App\Jobs;

use App\Finance\Fx\ExchangeRateStore;
use App\Finance\Fx\FrankfurterClient;
use App\Finance\Fx\FxRequestException;
use App\Finance\MoneyMath;
use App\Models\Posting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fills `postings.amount` (the reporting amount) for one user's unresolved
 * postings. Same-currency rows are copied over directly; foreign rows are
 * converted with shared, cached ECB rates — booking date or the latest
 * publication within seven days before it.
 *
 * The job is deliberately bounded and user-scoped: at most a few provider
 * requests behind a shared lock and cooldown, a short wall-clock budget, and
 * no internal retries — anything left unresolved is picked up by the next
 * sync or a manual retry. Works identically under the sync queue driver and
 * a worker.
 */
class ConvertReportingAmountsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const int MAX_REQUESTS = 3;

    private const int WALL_CLOCK_SECONDS = 20;

    private const int MAX_RANGE_DAYS = 366;

    private const int CHUNK_SIZE = 500;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(FrankfurterClient $client, ExchangeRateStore $rates): void
    {
        $user = User::query()->find($this->userId);
        $quote = $user?->reporting_currency;

        if ($quote === null) {
            return;
        }

        $deadline = microtime(true) + self::WALL_CLOCK_SECONDS;

        $this->copySameCurrencyAmounts($user, $quote);
        $this->applyCachedRates($user, $quote, $rates, $deadline);

        $groups = $this->unresolvedGroups($user, $quote, $rates);

        if ($groups === [] || $rates->inCooldown() || microtime(true) >= $deadline) {
            return;
        }

        $lock = $rates->acquireRequestLock();

        if ($lock === null) {
            return;
        }

        $fetchedBases = [];

        try {
            // Another worker may have just filled the cache — recheck.
            if (! $rates->inCooldown()) {
                $requests = 0;

                foreach ($groups as $base => $group) {
                    if ($requests >= self::MAX_REQUESTS || microtime(true) >= $deadline) {
                        break;
                    }

                    try {
                        $rows = $client->rates($base, [$quote], $group['from'], $group['to']);
                    } catch (FxRequestException $exception) {
                        if ($exception->kind === 'unsupported') {
                            $this->markDatesMissing($group['dates'], $base, $quote, $rates);

                            continue;
                        }

                        if ($exception->kind === 'access_denied') {
                            Log::warning('FX provider denied access; cooling down.', ['exception' => $exception]);
                        }

                        $rates->startCooldown($exception);

                        break;
                    }

                    $requests++;
                    $fetchedBases[] = $base;
                    $rates->storeRates($rows);
                }
            }
        } finally {
            $lock->release();
        }

        // Rates are shared, so what just landed (and what other workers
        // stored) resolves as much of the backlog as the budget allows.
        $this->applyCachedRates($user, $quote, $rates, $deadline);

        // Dates the provider demonstrably does not cover go to the bounded
        // negative cache so later runs do not spend requests on them.
        foreach ($fetchedBases as $base) {
            $this->markUncoveredDates($user, $base, $quote, $rates);
        }
    }

    /**
     * Postings whose account currency already is the reporting currency get
     * their reporting amount for free.
     */
    private function copySameCurrencyAmounts(User $user, string $quote): void
    {
        Posting::query()
            ->where('user_id', $user->id)
            ->whereNull('amount')
            ->whereNotNull('original_amount')
            ->where('original_currency', $quote)
            ->update(['amount' => DB::raw('original_amount'), 'currency' => $quote]);
    }

    /**
     * Walk unresolved postings and fill any whose rate is already cached.
     * Bounded by the wall-clock budget; leaves the rest for a later run.
     */
    private function applyCachedRates(User $user, string $quote, ExchangeRateStore $rates, float $deadline): void
    {
        Posting::query()
            ->where('user_id', $user->id)
            ->whereNull('amount')
            ->whereNotNull('original_amount')
            ->whereNotNull('original_currency')
            ->where('original_currency', '<>', $quote)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($postings) use ($quote, $rates, $deadline): bool {
                foreach ($postings as $posting) {
                    if (microtime(true) >= $deadline) {
                        return false;
                    }

                    $date = CarbonImmutable::parse($posting->date);
                    $hit = $rates->rateFor($posting->original_currency, $quote, $date);

                    if ($hit === null) {
                        continue;
                    }

                    Posting::query()
                        ->whereKey($posting->id)
                        ->whereNull('amount')
                        ->update([
                            'amount' => MoneyMath::round(bcmul($posting->original_amount, $hit['rate'], 20)),
                            'currency' => $quote,
                        ]);
                }

                return true;
            }, 'id');
    }

    /**
     * Group unresolved postings by account currency into one bounded request
     * range each: earliest needed date minus the publication-fallback window
     * through the latest needed date, capped at one year. Dates already
     * known-missing are skipped entirely.
     *
     * @return array<string, array{from: CarbonImmutable, to: CarbonImmutable, dates: list<CarbonImmutable>}>
     */
    private function unresolvedGroups(User $user, string $quote, ExchangeRateStore $rates): array
    {
        $groups = [];

        Posting::query()
            ->where('user_id', $user->id)
            ->whereNull('amount')
            ->whereNotNull('original_amount')
            ->whereNotNull('original_currency')
            ->where('original_currency', '<>', $quote)
            ->orderBy('date')
            ->limit(2000)
            ->get(['original_currency', 'date'])
            ->each(function (Posting $posting) use ($quote, $rates, &$groups): void {
                $base = (string) $posting->original_currency;
                $date = CarbonImmutable::parse($posting->date);

                if ($rates->isKnownMissing($base, $quote, $date)) {
                    return;
                }

                $groups[$base]['dates'][] = $date;
            });

        foreach ($groups as $base => &$group) {
            $dates = collect($group['dates']);
            $to = $dates->max();
            $from = $dates->min()->subDays(ExchangeRateStore::MAX_FALLBACK_DAYS);
            $earliest = $to->subDays(self::MAX_RANGE_DAYS);

            $group['from'] = $from->lt($earliest) ? $earliest : $from;
            $group['to'] = $to;
        }

        return $groups;
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     */
    private function markDatesMissing(array $dates, string $base, string $quote, ExchangeRateStore $rates): void
    {
        foreach ($dates as $date) {
            $rates->markMissing($base, $quote, $date);
        }
    }

    /**
     * After fetching a base currency, any unresolved date that still has no
     * usable rate is a genuine coverage gap — negative-cache it.
     */
    private function markUncoveredDates(User $user, string $base, string $quote, ExchangeRateStore $rates): void
    {
        Posting::query()
            ->where('user_id', $user->id)
            ->whereNull('amount')
            ->where('original_currency', $base)
            ->distinct()
            ->pluck('date')
            ->each(function ($date) use ($base, $quote, $rates): void {
                $date = CarbonImmutable::parse($date);

                if ($rates->rateFor($base, $quote, $date) === null) {
                    $rates->markMissing($base, $quote, $date);
                }
            });
    }
}
