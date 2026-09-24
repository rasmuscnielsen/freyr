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
    const [wantsImport, setWantsImport] = useState(initialImport !== null);
    const importFinished = status?.status === 'completed';

    if (!wantsImport && canImport) {
        return (
            <>
                <Head title="Import your Spiir data" />
                <div className="flex flex-col gap-6">
                    <p className="text-sm text-muted-foreground">
                        Spiir was a Danish personal finance app that let you
                        export your accounts, categories and transactions. If
                        you used it, you can bring that history into Freyr now.
                        If you have never heard of Spiir, just skip this step.
                    </p>

                    <div className="flex flex-col gap-3">
                        <Button
                            type="button"
                            onClick={() => setWantsImport(true)}
                            data-test="spiir-opt-in-button"
                        >
                            Yes, import my Spiir data
                        </Button>
                        <Form {...complete.form()} className="flex flex-col">
                            <Button
                                type="submit"
                                variant="secondary"
                                className="w-full"
                                data-test="finish-onboarding-button"
                            >
                                Skip, I don't use Spiir
                            </Button>
                        </Form>
                    </div>
                </div>
            </>
        );
    }

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
        description="Would you like to import your data from Spiir?"
        step="spiir"
    >
        {page}
    </OnboardingLayout>
);
