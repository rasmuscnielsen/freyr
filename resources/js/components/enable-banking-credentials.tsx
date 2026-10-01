import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export function EnableBankingIntro() {
    return (
        <div className="flex flex-col gap-3 text-sm text-muted-foreground">
            <p>
                Enable Banking connects Freyr to your bank. It is free for
                personal use.
            </p>
            <ol className="list-decimal space-y-1 pl-5">
                <li>
                    Create an account at{' '}
                    <TextLink
                        href="https://enablebanking.com"
                        target="_blank"
                        rel="noreferrer"
                    >
                        enablebanking.com
                    </TextLink>
                </li>
                <li>
                    Register an application in the control panel and whitelist
                    your own bank accounts
                </li>
                <li>
                    Copy the Application ID and download the private key (.pem)
                </li>
            </ol>
        </div>
    );
}

export function EnableBankingFields({
    appId,
    errors,
}: {
    appId?: string | null;
    errors: Partial<Record<'app_id' | 'private_key', string>>;
}) {
    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <Label htmlFor="app_id">Application ID</Label>
                <Input
                    id="app_id"
                    type="text"
                    required
                    name="app_id"
                    defaultValue={appId ?? ''}
                />
                <InputError message={errors.app_id} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="private_key">Private key (.pem)</Label>
                <Input
                    id="private_key"
                    type="file"
                    required
                    name="private_key"
                    accept=".pem,.key,.txt"
                />
                <InputError message={errors.private_key} />
            </div>
        </div>
    );
}
