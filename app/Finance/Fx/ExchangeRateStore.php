<?php

namespace App\Finance\Fx;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Shared, installation-wide ECB rate cache plus the provider-facing
 * coordination state: a cooldown that stops all users' jobs from re-hitting
 * a rate-limited provider, a request lock, and a bounded negative cache for
 * pairs/dates the provider provably does not cover.
 */
class ExchangeRateStore
{
    /**
     * A requested date resolves to the newest published rate on or before
     * it, at most this many calendar days back.
     */
    public const int MAX_FALLBACK_DAYS = 7;

    private const string COOLDOWN_KEY = 'fx.frankfurter.cooldown-until';

    private const string LOCK_KEY = 'fx.frankfurter.request-lock';

    private const int NEGATIVE_CACHE_DAYS = 7;

    private const int COVERAGE_CACHE_DAYS = 30;

    private const int VALUATION_CHECK_MINUTES = 60;

    /**
     * The latest stored rate usable for $date, or null when no publication
     * within the fallback window is cached.
     *
     * @return array{rate: string, effective_date: string}|null
     */
    public function rateFor(string $base, string $quote, CarbonImmutable $date): ?array
    {
        $row = ExchangeRate::query()
            ->where('provider', FrankfurterClient::PROVIDER)
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('effective_date', '<=', $date->toDateString())
            ->where('effective_date', '>=', $date->subDays(self::MAX_FALLBACK_DAYS)->toDateString())
            ->orderByDesc('effective_date')
            ->first(['rate', 'effective_date']);

        if ($row === null) {
            return null;
        }

        return ['rate' => (string) $row->rate, 'effective_date' => $row->effective_date->toDateString()];
    }

    /**
     * A historical fallback is safe only after the provider has been queried
     * for the requested date. An exact observation is always safe.
     *
     * @return array{rate: string, effective_date: string}|null
     */
    public function historicalRateFor(string $base, string $quote, CarbonImmutable $date): ?array
    {
        $rate = $this->rateFor($base, $quote, $date);

        if ($rate === null) {
            return null;
        }

        $expectedWeekendPublication = match (true) {
            $date->isSaturday() => $date->subDay()->toDateString(),
            $date->isSunday() => $date->subDays(2)->toDateString(),
            default => null,
        };

        return $rate['effective_date'] === $date->toDateString()
            || $rate['effective_date'] === $expectedWeekendPublication
            || $this->hasCoverage($base, $quote, $date)
            ? $rate
            : null;
    }

    public function markCoverage(string $base, string $quote, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $lastHistoricalDate = CarbonImmutable::yesterday();
        $date = $from;
        $to = $to->min($lastHistoricalDate);

        while ($date->lte($to)) {
            Cache::put($this->coverageKey($base, $quote, $date), true, now()->addDays(self::COVERAGE_CACHE_DAYS));
            $date = $date->addDay();
        }
    }

    public function hasCoverage(string $base, string $quote, CarbonImmutable $date): bool
    {
        return (bool) Cache::get($this->coverageKey($base, $quote, $date), false);
    }

    public function markValuationChecked(string $base, string $quote): void
    {
        Cache::put($this->valuationKey($base, $quote), true, now()->addMinutes(self::VALUATION_CHECK_MINUTES));
    }

    public function valuationWasChecked(string $base, string $quote): bool
    {
        return (bool) Cache::get($this->valuationKey($base, $quote), false);
    }

    /**
     * @param  list<array{date:string, base:string, quote:string, rate:string}>  $rows
     */
    public function storeRates(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        ExchangeRate::query()->upsert(
            array_map(fn (array $row): array => [
                'provider' => FrankfurterClient::PROVIDER,
                'base_currency' => $row['base'],
                'quote_currency' => $row['quote'],
                'effective_date' => $row['date'],
                'rate' => $row['rate'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $rows),
            ['provider', 'base_currency', 'quote_currency', 'effective_date'],
            ['rate', 'updated_at'],
        );
    }

    /**
     * Latest stored effective date for a pair — lets a fresh request fetch
     * only the tail it does not already hold.
     */
    public function latestStoredDate(string $base, string $quote): ?CarbonImmutable
    {
        $date = ExchangeRate::query()
            ->where('provider', FrankfurterClient::PROVIDER)
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->max('effective_date');

        return $date === null ? null : CarbonImmutable::parse($date);
    }

    /**
     * Negative cache: remember that a pair/date is unavailable so later runs
     * do not burn requests on it. Bounded — entries expire.
     */
    public function markMissing(string $base, string $quote, CarbonImmutable $date): void
    {
        Cache::put(
            $this->missingKey($base, $quote, $date),
            true,
            now()->addDays(self::NEGATIVE_CACHE_DAYS),
        );
    }

    public function isKnownMissing(string $base, string $quote, CarbonImmutable $date): bool
    {
        return (bool) Cache::get($this->missingKey($base, $quote, $date), false);
    }

    /**
     * The provider-wide cooldown deadline set by a rate limit or outage, or
     * null when requests may proceed.
     */
    public function cooldownUntil(): ?CarbonImmutable
    {
        $timestamp = Cache::get(self::COOLDOWN_KEY);

        return $timestamp === null ? null : CarbonImmutable::createFromTimestamp((int) $timestamp);
    }

    public function inCooldown(): bool
    {
        $until = $this->cooldownUntil();

        return $until !== null && $until->isFuture();
    }

    public function startCooldown(FxRequestException $exception): void
    {
        $seconds = $exception->cooldownSeconds();

        if ($seconds <= 0) {
            return;
        }

        Cache::put(self::COOLDOWN_KEY, now()->addSeconds($seconds)->getTimestamp(), now()->addSeconds($seconds));
    }

    /**
     * The single shared provider lock: only one job across the installation
     * talks to Frankfurter at a time. Returns null when another run holds it.
     */
    public function acquireRequestLock(): ?Lock
    {
        $lock = Cache::lock(self::LOCK_KEY, 30);

        return $lock->get() ? $lock : null;
    }

    private function missingKey(string $base, string $quote, CarbonImmutable $date): string
    {
        return "fx.miss.{$base}.{$quote}.{$date->toDateString()}";
    }

    private function coverageKey(string $base, string $quote, CarbonImmutable $date): string
    {
        return "fx.coverage.{$base}.{$quote}.{$date->toDateString()}";
    }

    private function valuationKey(string $base, string $quote): string
    {
        return "fx.valuation.{$base}.{$quote}";
    }
}
