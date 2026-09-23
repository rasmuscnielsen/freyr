import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Scope = { id: string; description: string };

export default function Authorize({
    client,
    scopes,
    authToken,
}: {
    client: { name: string };
    scopes: Scope[];
    authToken: string;
}) {
    return (
        <>
            <Head title="Authorize application" />

            <div className="space-y-6">
                <p className="text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {client.name}
                    </span>{' '}
                    is requesting access to your account. It will be able to:
                </p>

                {scopes.length > 0 ? (
                    <ul className="space-y-2">
                        {scopes.map((scope) => (
                            <li key={scope.id} className="grid gap-0.5">
                                <span className="font-mono text-xs font-medium">
                                    {scope.id}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    {scope.description}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        It requested no specific permissions.
                    </p>
                )}

                <div className="flex items-center gap-3">
                    <Form
                        action="/oauth/authorize"
                        method="post"
                        className="flex-1"
                    >
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="auth_token"
                                    value={authToken}
                                />
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Approve
                                </Button>
                            </>
                        )}
                    </Form>

                    <Form
                        action="/oauth/authorize"
                        method="delete"
                        className="flex-1"
                    >
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="auth_token"
                                    value={authToken}
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="w-full"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Deny
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

Authorize.layout = {
    title: 'Authorize application',
    description:
        'Review the permissions this third-party application is requesting.',
};
