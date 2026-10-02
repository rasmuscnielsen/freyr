import { Form, Head, router } from '@inertiajs/react';
import { CircleCheckIcon } from 'lucide-react';
import { useState } from 'react';
import {
    OpenAiApiKeyField,
    SimilarityIntro,
    SimilaritySinceSelect,
} from '@/components/openai-similarity';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
    enabled: boolean;
    since: string;
    autoCategorize: boolean;
};

export default function OnboardingSimilarity({
    openAi,
    enabled: initiallyEnabled,
    since: initialSince,
    autoCategorize: initialAutoCategorize,
}: Props) {
    const [enabled, setEnabled] = useState(
        initiallyEnabled || openAi.configured,
    );
    const [since, setSince] = useState(initialSince);
    const [autoCategorize, setAutoCategorize] = useState(initialAutoCategorize);
    const [showReplaceForm, setShowReplaceForm] = useState(false);
    const keyFieldVisible = !openAi.configured || showReplaceForm;

    return (
        <>
            <Head title="Similar postings" />
            <div className="flex flex-col gap-6">
                <SimilarityIntro />

                {openAi.configured && (
                    <Alert>
                        <CircleCheckIcon />
                        <AlertTitle>OpenAI is already configured</AlertTitle>
                        <AlertDescription>
                            {openAi.source === 'environment'
                                ? 'An API key is set via environment variables.'
                                : 'An API key is stored.'}
                        </AlertDescription>
                    </Alert>
                )}

                <Form
                    {...store.form()}
                    className="flex flex-col gap-6"
                    transform={(data) => ({
                        ...data,
                        enabled,
                        since,
                        auto_categorize: autoCategorize,
                    })}
                >
                    {({ processing, errors }) => (
                        <>
                            {keyFieldVisible && (
                                <OpenAiApiKeyField errors={errors} />
                            )}

                            {openAi.configured && !showReplaceForm && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="self-start px-0"
                                    onClick={() => setShowReplaceForm(true)}
                                >
                                    Replace API key
                                </Button>
                            )}

                            <div className="flex items-start space-x-3">
                                <Checkbox
                                    id="enabled"
                                    checked={enabled}
                                    onCheckedChange={(checked) =>
                                        setEnabled(checked === true)
                                    }
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor="enabled">
                                        Enable similar postings
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        You can turn this off later without
                                        removing the API key.
                                    </p>
                                </div>
                            </div>

                            {enabled && (
                                <div className="grid gap-2">
                                    <Label htmlFor="since">
                                        How far back should Freyr analyze
                                        postings?
                                    </Label>
                                    <SimilaritySinceSelect
                                        value={since}
                                        onValueChange={setSince}
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        Older postings outside this window are
                                        skipped — you can change it later.
                                    </p>
                                </div>
                            )}

                            {enabled && (
                                <div className="flex items-start space-x-3">
                                    <Checkbox
                                        id="auto_categorize"
                                        checked={autoCategorize}
                                        onCheckedChange={(checked) =>
                                            setAutoCategorize(checked === true)
                                        }
                                    />
                                    <div className="grid gap-1">
                                        <Label htmlFor="auto_categorize">
                                            Automatically categorize
                                        </Label>
                                        <p className="text-sm text-muted-foreground">
                                            When a nearly identical posting
                                            already has a category, apply it
                                            right away instead of only
                                            suggesting it. Off by default —
                                            suggestions alone are a good start.
                                        </p>
                                    </div>
                                </div>
                            )}

                            <div className="flex items-center gap-3">
                                <Button
                                    type="submit"
                                    className="flex-1"
                                    disabled={processing}
                                    data-test="finish-onboarding-button"
                                >
                                    {processing && <Spinner />}
                                    {enabled ? 'Save and finish' : 'Finish'}
                                </Button>
                                {enabled && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        disabled={processing}
                                        onClick={() =>
                                            router.post(complete.url())
                                        }
                                    >
                                        Skip
                                    </Button>
                                )}
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
        description="Optionally unlock smarter search and category suggestions with an OpenAI API key."
        step="similarity"
    >
        {page}
    </OnboardingLayout>
);
