import { usePage } from '@inertiajs/react';
import type { Region } from '@/types';

/**
 * The signed-in user's region preferences shared via Inertia: number-format
 * locale (derived from country), display timezone, and reporting currency.
 */
export function useRegion(): Region {
    const { region } = usePage().props;

    return (
        region ?? {
            country: null,
            timezone: null,
            reportingCurrency: null,
            locale: 'en-US',
            setupCompleted: false,
        }
    );
}
