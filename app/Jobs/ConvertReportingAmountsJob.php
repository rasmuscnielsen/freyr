<?php

namespace App\Jobs;

use App\Finance\Fx\ExchangeRateStore;
use App\Finance\Fx\FrankfurterClient;
use App\Finance\Fx\FxRequestException;
use App\Finance\MoneyMath;
use App\Models\BankAccount;
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

    /** @var list<int> */
    private array $conflictedPostingIds = [];

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

        if ($rates->inCooldown() || microtime(true) >= $deadline) {
            return;
        }

        $groups = $this->unresolvedGroups($user, $quote, $rates, $deadline);

        if ($groups === []) {
            return;
        }

        $lock = $rates->acquireRequestLock();

        if ($lock === null) {
            return;
        }

        try {
            // Another worker may have just filled the cache — recheck.
            if (! $rates->inCooldown()) {
                $requests = 0;

                $groups = $this->unresolvedGroups($user, $quote, $rates, $deadline);
                $unsupportedBases = [];

                foreach ($groups as $group) {
                    if ($requests >= self::MAX_REQUESTS || microtime(true) >= $deadline) {
                        break;
                    }

                    if (in_array($group['base'], $unsupportedBases, true)) {
                        continue;
                    }

                    $requests++;

                    try {
                        $rows = $client->rates($group['base'], [$quote], $group['from'], $group['to']);
                    } catch (FxRequestException $exception) {
                        if ($exception->kind === 'unsupported') {
                            $this->markDatesMissing($group['dates'], $group['base'], $quote, $rates);
                            $rates->markValuationChecked($group['base'], $quote);
                            $unsupportedBases[] = $group['base'];

                            continue;
                        }

                        if ($exception->kind === 'access_denied') {
                            Log::warning('FX provider denied access; cooling down.', ['exception' => $exception]);
                        }

                        $rates->startCooldown($exception);

                        break;
                    }

                    $rates->storeRates($rows);
                    $rates->markCoverage($group['base'], $quote, $group['from'], $group['to']);

                    if ($group['valuation']) {
                        $rates->markValuationChecked($group['base'], $quote);
                    }

                    $this->markUncoveredDates($group, $quote, $rates);
                }
            }
        } finally {
            $lock->release();
        }

        // Rates are shared, so what just landed (and what other workers
        // stored) resolves as much of the backlog as the budget allows.
        $this->applyCachedRates($user, $quote, $rates, $deadline);

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

                    if (in_array($posting->id, $this->conflictedPostingIds, true)) {
                        continue;
                    }

                    $date = CarbonImmutable::parse($posting->date);
                    $hit = $rates->historicalRateFor($posting->original_currency, $quote, $date);

                    if ($hit === null) {
                        continue;
                    }

                    $updated = Posting::query()
                        ->whereKey($posting->id)
                        ->whereNull('amount')
                        ->where('original_amount', $posting->original_amount)
                        ->where('original_currency', $posting->original_currency)
                        ->whereDate('date', $date->toDateString())
                        ->update([
                            'amount' => MoneyMath::round(bcmul($posting->original_amount, $hit['rate'], 20)),
                            'currency' => $quote,
                        ]);

                    if ($updated === 0) {
                        $this->conflictedPostingIds[] = $posting->id;
                    }
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
     * @return list<array{base: string, from: CarbonImmutable, to: CarbonImmutable, dates: list<CarbonImmutable>, valuation: bool}>
     */
    private function unresolvedGroups(User $user, string $quote, ExchangeRateStore $rates, float $deadline): array
    {
        $datesByBase = [];
        $selected = 0;

        Posting::query()
            ->where('user_id', $user->id)
            ->whereNull('amount')
            ->whereNotNull('original_amount')
            ->whereNotNull('original_currency')
            ->where('original_currency', '<>', $quote)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($postings) use ($quote, $rates, $deadline, &$datesByBase, &$selected): bool {
                foreach ($postings as $posting) {
                    if ($selected >= 2000 || microtime(true) >= $deadline) {
                        return false;
                    }

                    $base = (string) $posting->original_currency;
                    $date = CarbonImmutable::parse($posting->date);

                    if ($rates->isKnownMissing($base, $quote, $date)) {
                        continue;
                    }

                    $datesByBase[$base][] = $date;
                    $selected++;
                }

                return true;
            }, 'id');

        $historicalGroups = [];
        $valuationGroups = [];

        BankAccount::query()
            ->whereBelongsTo($user)
            ->where('is_active', true)
            ->whereNotNull('current_balance')
            ->where('currency', '<>', $quote)
            ->distinct()
            ->pluck('currency')
            ->each(function (string $base) use ($quote, $rates, &$valuationGroups): void {
                $today = CarbonImmutable::today();

                if ($rates->latestStoredDate($base, $quote)?->isSameDay($today)
                    || $rates->valuationWasChecked($base, $quote)) {
                    return;
                }

                $valuationGroups[] = [
                    'base' => $base,
                    'from' => $today->subDays(ExchangeRateStore::MAX_FALLBACK_DAYS),
                    'to' => $today,
                    'dates' => [],
                    'valuation' => true,
                ];
            });

        foreach ($datesByBase as $base => $dates) {
            $dates = collect($dates)->sort()->values();
            $from = $dates->first()->subDays(ExchangeRateStore::MAX_FALLBACK_DAYS);
            $to = $from->addDays(self::MAX_RANGE_DAYS)->min($dates->last());
            $requestedDates = $dates->filter(fn (CarbonImmutable $date): bool => $date->lte($to))->all();

            $historicalGroups[] = compact('base', 'from', 'to') + [
                'dates' => $requestedDates,
                'valuation' => false,
            ];
        }

        return collect($historicalGroups)
            ->zip($valuationGroups)
            ->flatten(1)
            ->filter()
            ->values()
            ->all();
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
    private function markUncoveredDates(array $group, string $quote, ExchangeRateStore $rates): void
    {
        foreach ($group['dates'] as $date) {
            if ($date->isToday()) {
                continue;
            }

            if ($rates->historicalRateFor($group['base'], $quote, $date) === null) {
                $rates->markMissing($group['base'], $quote, $date);
            }
        }
    }
}
