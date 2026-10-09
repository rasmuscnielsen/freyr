<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * The granularity an overview report aggregates postings by: monthly or
 * yearly periods.
 */
enum Interval: string
{
    case Month = 'month';

    case Year = 'year';

    /**
     * The series key of the period containing the date: 'YYYY-MM' or 'YYYY'.
     */
    public function key(CarbonImmutable $date): string
    {
        return $this === self::Month ? $date->format('Y-m') : $date->format('Y');
    }

    /**
     * The short chart label of the period containing the date.
     */
    public function label(CarbonImmutable $date): string
    {
        return $this === self::Month ? $date->format('M y') : $date->format('Y');
    }

    public function startOf(CarbonImmutable $date): CarbonImmutable
    {
        return $this === self::Month ? $date->startOfMonth() : $date->startOfYear();
    }

    public function endOf(CarbonImmutable $date): CarbonImmutable
    {
        return $this === self::Month ? $date->endOfMonth() : $date->endOfYear();
    }

    /**
     * Whether a focus key is well-formed for this interval: 'YYYY-MM' for
     * months, 'YYYY' for years.
     */
    public function matchesFocus(string $focus): bool
    {
        return preg_match($this === self::Month ? '/^\d{4}-\d{2}$/' : '/^\d{4}$/', $focus) === 1;
    }
}
