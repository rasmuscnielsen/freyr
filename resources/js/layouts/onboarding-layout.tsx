import AppLogoIcon from '@/components/layout/app-logo-icon';
import OnboardingSteps from '@/components/onboarding/onboarding-steps';
import type { OnboardingStep } from '@/components/onboarding/onboarding-steps';

export default function OnboardingLayout({
    title,
    description,
    step,
    children,
}: {
    title?: string;
    description?: string;
    step: OnboardingStep;
    children: React.ReactNode;
}) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-md">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <div className="mb-1 flex size-10 items-center justify-center rounded-lg bg-teal-300">
                            <AppLogoIcon className="size-6 fill-current text-teal-900" />
                        </div>

                        {(title || description) && (
                            <div className="space-y-2 text-center">
                                <h1 className="text-xl font-medium">{title}</h1>
                                {description && (
                                    <p className="text-center text-sm text-muted-foreground">
                                        {description}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="w-full">
                            <OnboardingSteps current={step} />
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
