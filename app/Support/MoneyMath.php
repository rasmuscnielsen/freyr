<?php

namespace App\Support;

/**
 * Decimal-string money arithmetic. Monetary values are stored and compared
 * as decimal strings — never binary floats — so conversions and balances do
 * not accumulate representation error.
 */
final class MoneyMath
{
    /**
     * Round a decimal string to $scale places, half away from zero
     * (a debit of -1.235 becomes -1.24, a credit of 1.235 becomes 1.24).
     */
    public static function round(string $value, int $scale = 2): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';
        $adjusted = bccomp($value, '0', 20) < 0
            ? bcsub($value, $offset, 20)
            : bcadd($value, $offset, 20);
        $result = bcadd($adjusted, '0', $scale);

        // bcadd can produce "-0.00" — normalize to a clean zero.
        return bccomp($result, '0', $scale) === 0
            ? bcadd('0', '0', $scale)
            : $result;
    }

    /**
     * Whether a value looks like a decimal number usable in bc math.
     */
    public static function isDecimal(mixed $value): bool
    {
        return is_numeric($value);
    }
}
