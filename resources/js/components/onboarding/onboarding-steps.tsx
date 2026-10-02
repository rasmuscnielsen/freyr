import { CheckIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type OnboardingStep =
    | 'account'
    | 'region'
    | 'bank'
    | 'spiir'
    | 'similarity';

const steps: { key: OnboardingStep; label: string }[] = [
    { key: 'account', label: 'Account' },
    { key: 'region', label: 'Region' },
    { key: 'bank', label: 'Bank' },
    { key: 'spiir', label: 'Spiir import' },
    { key: 'similarity', label: 'Similar postings' },
];

export default function OnboardingSteps({
    current,
}: {
    current: OnboardingStep;
}) {
    const currentIndex = steps.findIndex((step) => step.key === current);

    return (
        <ol className="flex items-center gap-2">
            {steps.map((step, index) => {
                const done = index < currentIndex;
                const active = index === currentIndex;

                return (
                    <li
                        key={step.key}
                        className="flex flex-1 flex-col items-center gap-1.5"
                    >
                        <div
                            className={cn(
                                'flex size-7 items-center justify-center rounded-full border text-xs font-medium',
                                done &&
                                    'border-primary bg-primary text-primary-foreground',
                                active && 'border-primary text-primary',
                                !done &&
                                    !active &&
                                    'border-muted-foreground/30 text-muted-foreground',
                            )}
                        >
                            {done ? (
                                <CheckIcon className="size-3.5" />
                            ) : (
                                index + 1
                            )}
                        </div>
                        <span
                            className={cn(
                                'text-center text-xs',
                                active
                                    ? 'font-medium text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {step.label}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
