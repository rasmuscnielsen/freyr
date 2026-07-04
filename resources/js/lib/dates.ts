/**
 * Date formatting that follows the browser locale, mirroring how money is
 * formatted in `money.ts`. Accepts the `Y-m-d` / ISO strings the backend
 * sends and renders them in a human-readable form.
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value.slice(0, 10));

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(date);
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
