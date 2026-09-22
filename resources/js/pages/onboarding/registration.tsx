import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { store } from '@/routes/onboarding/registration';

export default function OnboardingRegistration({
    registrationEnabled,
}: {
    registrationEnabled: boolean;
}) {
    const { data, setData, post, processing } = useForm({
        enabled: registrationEnabled,
    });

    return (
        <>
            <Head title="Registration" />
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    post(store.url());
                }}
                className="flex flex-col gap-6"
            >
                <div className="flex items-center space-x-3">
                    <Checkbox
                        id="enabled"
                        checked={data.enabled}
                        onCheckedChange={(checked) =>
                            setData('enabled', checked === true)
                        }
                    />
                    <Label htmlFor="enabled">
                        Allow other people to sign up on this instance
                    </Label>
                </div>

                <Button
                    type="submit"
                    className="w-full"
                    disabled={processing}
                    data-test="continue-button"
                >
                    {processing && <Spinner />}
                    Continue
                </Button>
            </form>
        </>
    );
}

OnboardingRegistration.layout = (page: React.ReactNode) => (
    <OnboardingLayout
        title="Registration"
        description="Allow other people to sign up on this instance? You can change this later in Platform → Settings."
        step="registration"
    >
        {page}
    </OnboardingLayout>
);
