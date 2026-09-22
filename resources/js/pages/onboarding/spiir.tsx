import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import SpiirImportForm from '@/components/spiir-import-form';
import { Button } from '@/components/ui/button';
import type { SpiirImportStatus } from '@/hooks/use-spiir-import-status';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { complete } from '@/routes/onboarding';
import { store } from '@/routes/onboarding/spiir';

export default function OnboardingSpiir({
    canImport,
    hasDefaultCategories,
    import: initialImport,
}: {
    canImport: boolean;
    hasDefaultCategories: boolean;
    import: SpiirImportStatus | null;
}) {
    const [status, setStatus] = useState(initialImport);
    const importFinished = status?.status === 'completed';

    return (
        <>
            <Head title="Import your Spiir data" />
            <div className="flex flex-col gap-6">
                <SpiirImportForm
                    action={store.form()}
                    canImport={canImport}
                    hasDefaultCategories={hasDefaultCategories}
                    initialImport={initialImport}
                    onStatusChange={setStatus}
                />

                <Form {...complete.form()} className="flex flex-col">
                    <Button
                        type="submit"
                        variant={
                            importFinished || !canImport
                                ? 'default'
                                : 'secondary'
                        }
                        data-test="finish-onboarding-button"
                    >
                        {importFinished || !canImport ? 'Finish' : 'Skip'}
                    </Button>
                </Form>
            </div>
        </>
    );
}

OnboardingSpiir.layout = (page: React.ReactNode) => (
    <OnboardingLayout
        title="Import your Spiir data"
        description="Bring your accounts and postings over from Spiir, or skip and start fresh."
        step="spiir"
    >
        {page}
    </OnboardingLayout>
);
