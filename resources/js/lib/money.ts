/**
 * Money and number formatting that follows the browser locale, so separators
 * and symbol placement match the user's own conventions. Amounts are always
 * formatted in the currency they are stored in.
 */
export function formatMoney(
    amount: number,
    currency?: string | null,
    options: Intl.NumberFormatOptions = {},
): string {
    if (currency) {
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency,
                ...options,
            }).format(amount);
        } catch {
            // Unknown/invalid currency code — fall back to a plain number.
        }
    }

    return formatNumber(amount, options);
}

export function formatNumber(
    amount: number,
    options: Intl.NumberFormatOptions = {},
): string {
    return new Intl.NumberFormat(undefined, options).format(amount);
}
