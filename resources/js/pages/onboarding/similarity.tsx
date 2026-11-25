import { Form, Head, router } from '@inertiajs/react';
import { CircleCheckIcon } from 'lucide-react';
import { useState } from 'react';
import {
    OpenAiApiKeyField,
    SimilarityHighlights,
} from '@/components/openai-similarity';
import type { SimilarityCostEstimate } from '@/components/openai-similarity';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { complete } from '@/routes/onboarding';
import { store } from '@/routes/onboarding/similarity';

type Props = {
    openAi: {
        configured: boolean;
        source: 'database' | 'environment' | null;
    };
    autoCategorize: boolean;
    cost: SimilarityCostEstimate;
};

/**
 * Submitting turns the feature on (a key is required unless one is already
 * configured); skipping finishes onboarding without touching it.
 */
export default function OnboardingSimilarity({
    openAi,
    autoCategorize: initialAutoCategorize,
    cost,
}: Props) {
    const [autoCategorize, setAutoCategorize] = useState(initialAutoCategorize);
    const [replacingKey, setReplacingKey] = useState(false);

    return (
        <>
            <Head title="Similar postings" />
            <div className="flex flex-col gap-6">
                <SimilarityHighlights cost={cost} />

                <Form
                    {...store.form()}
                    className="flex flex-col gap-6"
                    transform={(data) => ({
                        ...data,
                        enabled: true,
                        auto_categorize: autoCategorize,
                    })}
                >
                    {({ processing, errors }) => (
                        <>
                            {openAi.configured && !replacingKey ? (
                                <div className="flex items-center justify-between gap-4 rounded-md border px-3 py-2 text-sm">
                                    <span className="flex items-center gap-2">
                                        <CircleCheckIcon className="size-4 text-green-600 dark:text-green-500" />
                                        {openAi.source === 'environment'
                                            ? 'OpenAI key found in environment'
                                            : 'OpenAI key saved'}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setReplacingKey(true)}
                                    >
                                        Change
                                    </Button>
                                </div>
                            ) : (
                                <OpenAiApiKeyField
                                    errors={errors}
                                    required={!openAi.configured}
                                />
                            )}

                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="auto_categorize"
                                    checked={autoCategorize}
                                    onCheckedChange={(checked) =>
                                        setAutoCategorize(checked === true)
                                    }
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor="auto_categorize">
                                        Apply categories automatically
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        Only when a near-identical posting is
                                        already categorized.
                                    </p>
                                </div>
                            </div>

                            <div className="flex flex-col gap-3">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="finish-onboarding-button"
                                >
                                    {processing && <Spinner />}
                                    Turn on and finish
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={processing}
                                    onClick={() => router.post(complete.url())}
                                >
                                    Skip for now
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

OnboardingSimilarity.layout = (page: React.ReactNode) => (
    <OnboardingLayout
        title="Similar postings"
        description="Optional. Let Freyr suggest categories using your own OpenAI key."
        step="similarity"
    >
        {page}
    </OnboardingLayout>
);
