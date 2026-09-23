import { Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Scope = { id: string; description: string };

export default function Authorize({
    client,
    scopes,
    authToken,
    csrfToken,
}: {
    client: { name: string };
    scopes: Scope[];
    authToken: string;
    csrfToken: string;
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

                {/* Native forms, not Inertia: the response is a cross-origin
                    redirect to the client's redirect_uri, which the browser
                    must perform as a full page navigation. */}
                <div className="flex items-center gap-3">
                    <form
                        method="POST"
                        action="/oauth/authorize"
                        className="flex-1"
                    >
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button type="submit" className="w-full">
                            Approve
                        </Button>
                    </form>

                    <form
                        method="POST"
                        action="/oauth/authorize"
                        className="flex-1"
                    >
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input type="hidden" name="_method" value="DELETE" />
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            className="w-full"
                        >
                            Deny
                        </Button>
                    </form>
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
