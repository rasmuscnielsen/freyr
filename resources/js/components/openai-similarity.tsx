import { CoinsIcon, ShieldCheckIcon, SparklesIcon } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type SimilarityCostEstimate = {
    pendingPostings: number;
    pendingUsd: number;
    usdPer10k: number;
};

function formatUsd(usd: number): string {
    return usd < 0.01 ? 'Under $0.01' : `About $${usd.toFixed(2)}`;
}

/**
 * What the feature does, what leaves the instance and what it costs. Shared
 * by the onboarding step and Platform → Settings. The cost speaks to postings
 * already in Freyr when there are any (e.g. from a Spiir import), otherwise
 * to postings that will arrive from bank connections.
 */
export function SimilarityHighlights({
    cost,
}: {
    cost: SimilarityCostEstimate;
}) {
    const items = [
        {
            icon: SparklesIcon,
            text: 'Suggests categories based on postings you have already sorted',
        },
        {
            icon: ShieldCheckIcon,
            text: 'Only descriptions go to OpenAI, never amounts or accounts',
        },
        {
            icon: CoinsIcon,
            text:
                cost.pendingPostings > 0
                    ? `${formatUsd(cost.pendingUsd)} for your ${cost.pendingPostings.toLocaleString('en')} postings, billed by OpenAI`
                    : `${formatUsd(cost.usdPer10k)} per 10,000 postings, billed by OpenAI`,
        },
    ];

    return (
        <ul className="grid gap-3 text-sm">
            {items.map(({ icon: Icon, text }) => (
                <li key={text} className="flex items-start gap-3">
                    <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <span>{text}</span>
                </li>
            ))}
        </ul>
    );
}

export function OpenAiApiKeyField({
    errors,
    required = true,
}: {
    errors: Partial<Record<'api_key', string>>;
    required?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <div className="flex items-center justify-between">
                <Label htmlFor="api_key">OpenAI API key</Label>
                <TextLink
                    href="https://platform.openai.com/api-keys"
                    target="_blank"
                    rel="noreferrer"
                    className="text-sm"
                >
                    Get a key
                </TextLink>
            </div>
            <Input
                id="api_key"
                type="password"
                required={required}
                name="api_key"
                placeholder="sk-..."
                autoComplete="off"
            />
            <p className="text-sm text-muted-foreground">Stored encrypted.</p>
            <InputError message={errors.api_key} />
        </div>
    );
}
