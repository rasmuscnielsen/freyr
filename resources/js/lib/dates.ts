/**
 * Date formatting that follows the browser locale, mirroring how money is
 * formatted in `money.ts`. Accepts the `Y-m-d` / ISO strings the backend
 * sends and renders them in a human-readable form.
 */
function format(
    value: string | null | undefined,
    options: Intl.DateTimeFormatOptions,
): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value.slice(0, 10));

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(undefined, options).format(date);
}

export function formatDate(value: string | null | undefined): string {
    return format(value, { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Day + month without the year, e.g. "Jun 27" — for tight columns where the
 * year is implied by context.
 */
export function formatDayMonth(value: string | null | undefined): string {
    return format(value, { day: 'numeric', month: 'short' });
}

/**
 * Formats a `Y-m` or `Y-m-d` string as a month label, e.g. "Jul 2025".
 */
export function formatMonth(value: string): string {
    const [year, month] = value.split('-').map(Number);
    const date = new Date(year, (month || 1) - 1, 1);

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        year: 'numeric',
    }).format(date);
}
