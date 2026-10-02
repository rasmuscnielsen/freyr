import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export const SIMILARITY_SINCE_OPTIONS = [
    { value: '1y', label: 'The last year' },
    { value: '2y', label: 'The last 2 years' },
    { value: '5y', label: 'The last 5 years' },
    { value: 'all', label: 'All time' },
] as const;

/**
 * What the feature does and why it needs an OpenAI key. Shared by the
 * onboarding step and Platform → Settings.
 */
export function SimilarityIntro() {
    return (
        <div className="flex flex-col gap-3 text-sm text-muted-foreground">
            <p>
                With an OpenAI API key, Freyr can understand your posting
                descriptions and find similar postings — powering smarter
                search and automatic category suggestions.
            </p>
            <p>
                It is entirely optional — everything works without it.
                Posting descriptions are sent to OpenAI for analysis, and
                usage is billed by OpenAI (fractions of a cent per posting).
                Create a key at{' '}
                <TextLink
                    href="https://platform.openai.com/api-keys"
                    target="_blank"
                    rel="noreferrer"
                >
                    platform.openai.com
                </TextLink>
                .
            </p>
        </div>
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
            <Label htmlFor="api_key">OpenAI API key</Label>
            <Input
                id="api_key"
                type="password"
                required={required}
                name="api_key"
                placeholder="sk-..."
                autoComplete="off"
            />
            <p className="text-sm text-muted-foreground">
                Stored encrypted in the database — it is never shown again.
            </p>
            <InputError message={errors.api_key} />
        </div>
    );
}

export function SimilaritySinceSelect({
    id = 'since',
    name = 'since',
    value,
    defaultValue,
    onValueChange,
    disabled,
}: {
    id?: string;
    name?: string;
    value?: string;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
    disabled?: boolean;
}) {
    return (
        <>
            {name !== undefined && (
                <input type="hidden" name={name} value={value ?? defaultValue} />
            )}
            <Select
                value={value}
                defaultValue={defaultValue}
                onValueChange={onValueChange}
                disabled={disabled}
            >
                <SelectTrigger id={id} className="bg-card">
                    <SelectValue placeholder="How far back to analyze" />
                </SelectTrigger>
                <SelectContent>
                    {SIMILARITY_SINCE_OPTIONS.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </>
    );
}
