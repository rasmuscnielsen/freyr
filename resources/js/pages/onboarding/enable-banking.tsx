import { Form, Head } from '@inertiajs/react';
import { CircleCheckIcon } from 'lucide-react';
import { useState } from 'react';
import {
    EnableBankingFields,
    EnableBankingIntro,
} from '@/components/enable-banking-credentials';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { skip, store } from '@/routes/onboarding/enable-banking';

export default function OnboardingEnableBanking({
    configured,
    appId,
}: {
    configured: boolean;
    appId: string | null;
}) {
    const [showReplaceForm, setShowReplaceForm] = useState(false);
    const formCollapsed = configured && !showReplaceForm;

    return (
        <>
            <Head title="Connect your bank" />
            <div className="flex flex-col gap-6">
                <EnableBankingIntro />

                {configured && (
                    <Alert>
                        <CircleCheckIcon />
                        <AlertTitle>
                            Enable Banking is already configured
                        </AlertTitle>
                        <AlertDescription>
                            <p>
                                {appId
                                    ? `Using application ${appId}.`
                                    : 'Credentials are set.'}
                            </p>
                            <Form {...skip.form()} className="mt-2">
                                <Button type="submit">Continue</Button>
                            </Form>
                        </AlertDescription>
                    </Alert>
                )}

                {configured && formCollapsed && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="self-start px-0"
                        onClick={() => setShowReplaceForm(true)}
                    >
                        Replace credentials
                    </Button>
                )}

                {!formCollapsed && (
                    <Form {...store.form()} className="flex flex-col gap-6">
                        {({ processing, errors }) => (
                            <>
                                <EnableBankingFields
                                    appId={appId}
                                    errors={errors}
                                />

                                <div className="flex items-center gap-3">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        data-test="save-enable-banking-button"
                                    >
                                        {processing && <Spinner />}
                                        Save and continue
                                    </Button>
                                    {!configured && (
                                        <Form {...skip.form()}>
                                            <Button
                                                type="submit"
                                                variant="secondary"
                                            >
                                                Skip for now
                                            </Button>
                                        </Form>
                                    )}
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

OnboardingEnableBanking.layout = (page: React.ReactNode) => (
    <OnboardingLayout
        title="Connect your bank"
        description="Set up Enable Banking so Freyr can fetch your bank accounts and transactions."
        step="bank"
    >
        {page}
    </OnboardingLayout>
);
