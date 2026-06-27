import { Form } from '@inertiajs/react';
import { Copy, KeyRound, Trash2 } from 'lucide-react';
import PersonalAccessTokenController from '@/actions/App/Http/Controllers/Settings/PersonalAccessTokenController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';

type PersonalAccessToken = {
    id: number;
    name: string;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type Props = {
    personalAccessTokens?: PersonalAccessToken[];
    plainTextPersonalAccessToken?: string | null;
};

const EmptyState = () => {
    return (
        <div className="p-8 text-center">
            <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-muted">
                <KeyRound className="h-7 w-7 text-muted-foreground" />
            </div>
            <p className="font-medium">No personal access tokens yet</p>
            <p className="mt-1 text-sm text-muted-foreground">
                Create a token to access the read-only API.
            </p>
        </div>
    );
};

export default function ManagePersonalAccessTokens(props: Props) {
    const tokens = props.personalAccessTokens ?? [];
    const [, copy] = useClipboard();

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Personal access tokens"
                description="Manage read-only API tokens for your account"
            />

            {props.plainTextPersonalAccessToken ? (
                <Alert>
                    <KeyRound />
                    <AlertTitle>Token created</AlertTitle>
                    <AlertDescription>
                        <div className="flex w-full flex-col gap-3">
                            <p>Copy this token now. It will not be shown again.</p>
                            <div className="flex gap-2">
                                <Input
                                    readOnly
                                    value={props.plainTextPersonalAccessToken}
                                    className="font-mono text-xs"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={() =>
                                        copy(
                                            props.plainTextPersonalAccessToken ?? '',
                                        )
                                    }
                                    aria-label="Copy token"
                                >
                                    <Copy />
                                </Button>
                            </div>
                        </div>
                    </AlertDescription>
                </Alert>
            ) : null}

            <div className="overflow-hidden rounded-lg border border-border">
                {tokens.length > 0 ? (
                    tokens.map((token) => (
                        <div
                            key={token.id}
                            className="flex flex-col gap-4 border-b border-border p-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div className="min-w-0">
                                <p className="truncate font-medium">
                                    {token.name}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Created {token.created_at_diff}
                                    {token.last_used_at_diff
                                        ? ` · Last used ${token.last_used_at_diff}`
                                        : ' · Never used'}
                                </p>
                            </div>

                            <Form
                                {...PersonalAccessTokenController.destroy.form(
                                    token.id,
                                )}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        <Trash2 />
                                        Revoke
                                    </Button>
                                )}
                            </Form>
                        </div>
                    ))
                ) : (
                    <EmptyState />
                )}
            </div>

            <Form
                {...PersonalAccessTokenController.store.form()}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="space-y-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="token-name">Token name</Label>
                            <Input
                                id="token-name"
                                name="name"
                                placeholder="Local scripts"
                                autoComplete="off"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <Button type="submit" disabled={processing}>
                            <KeyRound />
                            Create token
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}
