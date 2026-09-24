import { useEffect, useState } from 'react';
import { show } from '@/routes/spiir-import';

export type SpiirImportSummary = {
    user_id: number;
    accounts: number;
    postings: number;
    counter_entries: number;
};

export type SpiirImportStatus = {
    id: string;
    status: 'pending' | 'running' | 'completed' | 'failed';
    summary?: SpiirImportSummary | null;
    error?: string | null;
};

const POLLING_STATUSES = new Set(['pending', 'running']);

export function useSpiirImportStatus(
    initialImport: SpiirImportStatus | null,
): SpiirImportStatus | null {
    const [status, setStatus] = useState<SpiirImportStatus | null>(
        initialImport,
    );
    const [previousImport, setPreviousImport] =
        useState<SpiirImportStatus | null>(initialImport);

    if (previousImport !== initialImport) {
        setPreviousImport(initialImport);
        setStatus(initialImport);
    }

    useEffect(() => {
        if (!status || !POLLING_STATUSES.has(status.status)) {
            return;
        }

        const interval = setInterval(async () => {
            try {
                const response = await fetch(show.url(status.id), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (response.ok) {
                    setStatus(await response.json());
                }
            } catch {
                // Transient errors are ignored; polling continues.
            }
        }, 2000);

        return () => clearInterval(interval);
    }, [status]);

    return status;
}
