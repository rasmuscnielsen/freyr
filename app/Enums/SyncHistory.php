<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How far back transactions should be imported when a remote bank account is
 * linked for the first time. The chosen cutoff is stored on the bank account
 * as `ignore_postings_before`, so history the bank returns beyond it is
 * filtered out during import.
 */
enum SyncHistory: string
{
    case OneYear = '1y';

    case TwoYears = '2y';

    case Max = 'max';

    public function cutoffDate(): ?CarbonImmutable
    {
        return match ($this) {
            self::OneYear => CarbonImmutable::now()->subYear(),
            self::TwoYears => CarbonImmutable::now()->subYears(2),
            self::Max => null,
        };
    }
}
