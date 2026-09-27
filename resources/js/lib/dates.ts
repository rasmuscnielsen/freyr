/**
 * Date formatting that follows the user's country-derived locale, mirroring
 * how money is formatted in `money.ts`. Accepts the `Y-m-d` / ISO strings
 * the backend sends and renders them in a human-readable form. Date-only
 * values are never shifted across days — `timeZone` applies only to real
 * timestamps.
 */
function format(
    value: string | null | undefined,
    options: Intl.DateTimeFormatOptions,
    locale?: string,
): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value.slice(0, 10));

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(locale, options).format(date);
}

export function formatDate(
    value: string | null | undefined,
    locale?: string,
): string {
    return format(
        value,
        { day: 'numeric', month: 'short', year: 'numeric' },
        locale,
    );
}

/**
 * Day + month without the year, e.g. "Jun 27" — for tight columns where the
 * year is implied by context.
 */
export function formatDayMonth(
    value: string | null | undefined,
    locale?: string,
): string {
    return format(value, { day: 'numeric', month: 'short' }, locale);
}

/**
 * Human-friendly timestamp for recent events like syncs: "Just now" for the
 * last five minutes, "Today/Yesterday at 11:08" while the day is still
 * memorable, and an absolute date beyond that — the year only when it
 * differs from the current one.
 */
export function formatRelativeDateTime(
    value: string | null,
    timeZone?: string | null,
    locale?: string,
): string {
    if (value === null) {
        return 'Never';
    }

    const date = new Date(value.replace(' ', 'T'));
    const zone = timeZone ?? undefined;
    const now = new Date();
    const minutesAgo = (now.getTime() - date.getTime()) / 60_000;

    if (minutesAgo >= 0 && minutesAgo < 5) {
        return 'Just now';
    }

    const time = new Intl.DateTimeFormat(locale, {
        hour: 'numeric',
        minute: '2-digit',
        timeZone: zone,
    }).format(date);

    // "Today"/"Yesterday" are judged in the user's timezone, not the
    // browser's, so a timestamp near midnight lands on the right day.
    const startOfDay = (day: Date) => {
        const parts = new Intl.DateTimeFormat('en-CA', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            timeZone: zone,
        }).formatToParts(day);
        const get = (type: string) =>
            Number(parts.find((part) => part.type === type)?.value ?? 0);

        return Date.UTC(get('year'), get('month') - 1, get('day'));
    };
    const daysAgo = Math.round(
        (startOfDay(now) - startOfDay(date)) / 86_400_000,
    );

    if (daysAgo <= 0) {
        return `Today at ${time}`;
    }

    if (daysAgo === 1) {
        return `Yesterday at ${time}`;
    }

    const options: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
        timeZone: zone,
    };

    if (date.getFullYear() !== now.getFullYear()) {
        options.year = 'numeric';
    }

    return new Intl.DateTimeFormat(locale, options).format(date);
}

/**
 * Formats a `Y-m` or `Y-m-d` string as a month label, e.g. "Jul 2025".
 */
export function formatMonth(value: string, locale?: string): string {
    const [year, month] = value.split('-').map(Number);
    const date = new Date(year, (month || 1) - 1, 1);

    return new Intl.DateTimeFormat(locale, {
        month: 'short',
        year: 'numeric',
    }).format(date);
}
