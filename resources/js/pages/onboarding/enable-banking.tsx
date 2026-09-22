import { Form, Head } from '@inertiajs/react';
import { CircleCheckIcon } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
                <div className="flex flex-col gap-3 text-sm text-muted-foreground">
                    <p>
                        Enable Banking connects Freyr to your bank. It is free
                        for personal use.
                    </p>
                    <ol className="list-decimal space-y-1 pl-5">
                        <li>
                            Create an account at{' '}
                            <TextLink
                                href="https://enablebanking.com"
                                target="_blank"
                                rel="noreferrer"
                            >
                                enablebanking.com
                            </TextLink>
                        </li>
                        <li>
                            Register an application in the control panel and
                            whitelist your own bank accounts
                        </li>
                        <li>
                            Copy the Application ID and download the private key
                            (.pem)
                        </li>
                    </ol>
                </div>

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
                                <div className="grid gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="app_id">
                                            Application ID
                                        </Label>
                                        <Input
                                            id="app_id"
                                            type="text"
                                            required
                                            name="app_id"
                                            defaultValue={appId ?? ''}
                                        />
                                        <InputError message={errors.app_id} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="private_key">
                                            Private key (.pem)
                                        </Label>
                                        <Input
                                            id="private_key"
                                            type="file"
                                            required
                                            name="private_key"
                                            accept=".pem,.key,.txt"
                                        />
                                        <InputError
                                            message={errors.private_key}
                                        />
                                    </div>
                                </div>

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
