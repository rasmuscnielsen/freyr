import { router } from '@inertiajs/react';
import type { FormEvent } from 'react';

type DevLoginLinkProps = {
    keyId: string;
    label: string;
    redirectUrl?: string;
    className?: string;
};

/**
 * Local-development shortcut that logs in via spatie/laravel-login-link.
 * Intentionally avoids importing the package's own component so production
 * builds do not depend on the package being installed.
 */
export default function DevLoginLink({
    keyId,
    label,
    redirectUrl,
    className,
}: DevLoginLinkProps) {
    function submit(event: FormEvent) {
        event.preventDefault();
        router.post('/laravel-login-link-login', {
            key: keyId,
            redirect_url: redirectUrl ?? null,
        });
    }

    return (
        <form onSubmit={submit}>
            <button className={className} type="submit">
                {label}
            </button>
        </form>
    );
}
