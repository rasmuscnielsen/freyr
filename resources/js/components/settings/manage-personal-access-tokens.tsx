import { Form, usePage } from '@inertiajs/react';
import { Copy, KeyRound, SquareArrowOutUpRight, Trash2 } from 'lucide-react';
import PersonalAccessTokenController from '@/actions/App/Http/Controllers/Settings/PersonalAccessTokenController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';

type PersonalAccessToken = {
    id: string;
    name: string;
    created_at_diff: string;
    expires_at_diff: string | null;
};

export type TokenScope = {
    id: string;
    description: string;
};

export type Props = {
    personalAccessTokens?: PersonalAccessToken[];
    plainTextPersonalAccessToken?: string | null;
    availableTokenScopes?: TokenScope[];
};

const EmptyState = () => {
    return (
        <div className="p-8 text-center">
            <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-muted">
                <KeyRound className="h-7 w-7 text-muted-foreground" />
            </div>
            <p className="font-medium">No personal access tokens yet</p>
            <p className="mt-1 text-sm text-muted-foreground">
                Create a token to access the API or connect an MCP client.
            </p>
        </div>
    );
};

export default function ManagePersonalAccessTokens(props: Props) {
    const tokens = props.personalAccessTokens ?? [];
    const scopes = props.availableTokenScopes ?? [];
    const [, copy] = useClipboard();
    const { auth } = usePage().props;

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Personal access tokens"
                description="Manage API tokens for your account"
            />

            {auth.isPlatformAdmin && (
                <a
                    href="/docs/api"
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    <SquareArrowOutUpRight className="h-3.5 w-3.5" />
                    API documentation
                </a>
            )}

            {props.plainTextPersonalAccessToken ? (
                <Alert>
                    <KeyRound />
                    <AlertTitle>Token created</AlertTitle>
                    <AlertDescription>
                        <div className="flex w-full flex-col gap-3">
                            <p>
                                Copy this token now. It will not be shown again.
                            </p>
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
                                            props.plainTextPersonalAccessToken ??
                                                '',
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
                                    {token.expires_at_diff
                                        ? ` · Expires ${token.expires_at_diff}`
                                        : ''}
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

                        {scopes.length > 0 ? (
                            <div className="grid gap-2">
                                <Label>Scopes</Label>
                                <div className="space-y-2">
                                    {scopes.map((scope) => (
                                        <label
                                            key={scope.id}
                                            className="flex items-start gap-3"
                                        >
                                            <Checkbox
                                                name="scopes[]"
                                                value={scope.id}
                                                defaultChecked
                                                className="mt-0.5"
                                            />
                                            <span className="grid gap-0.5">
                                                <span className="font-mono text-xs font-medium">
                                                    {scope.id}
                                                </span>
                                                <span className="text-sm text-muted-foreground">
                                                    {scope.description}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.scopes} />
                            </div>
                        ) : null}

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
