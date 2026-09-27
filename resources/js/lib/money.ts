/**
 * Money and number formatting that follows the user's country-derived locale
 * (e.g. `da-DK` for Denmark), so separators and symbol placement match their
 * conventions. Amounts are always formatted in the currency they are stored
 * in — account money in the account currency, reported figures in the
 * user's reporting currency.
 */
export function formatMoney(
    amount: number,
    currency?: string | null,
    locale?: string,
    options: Intl.NumberFormatOptions = {},
): string {
    if (currency) {
        try {
            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency,
                ...options,
            }).format(amount);
        } catch {
            // Unknown/invalid currency code — fall back to a plain number.
        }
    }

    return formatNumber(amount, locale, options);
}

export function formatNumber(
    amount: number,
    locale?: string,
    options: Intl.NumberFormatOptions = {},
): string {
    return new Intl.NumberFormat(locale, options).format(amount);
}
