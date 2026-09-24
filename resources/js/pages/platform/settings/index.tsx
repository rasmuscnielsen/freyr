import { Head, router, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import SpiirImportForm from '@/components/spiir-import-form';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { SpiirImportStatus } from '@/hooks/use-spiir-import-status';
import { edit as settingsEdit } from '@/routes/platform/settings';
import {
    destroy as destroyEnableBanking,
    update as updateEnableBanking,
} from '@/routes/platform/settings/enable-banking';
import { update as updateRegistration } from '@/routes/platform/settings/registration';
import { store as storeSpiirImport } from '@/routes/spiir-import';
import type { BreadcrumbItem } from '@/types';

type Props = {
    registrationEnabled: boolean;
    enableBanking: {
        configured: boolean;
        appId: string | null;
        source: 'database' | 'environment' | null;
    };
    spiir: {
        canImport: boolean;
        hasDefaultCategories: boolean;
    };
    import: SpiirImportStatus | null;
};

export default function PlatformSettingsIndex({
    registrationEnabled,
    enableBanking,
    spiir,
    import: initialImport,
}: Props) {
    const registration = useForm({ enabled: registrationEnabled });
    const credentials = useForm<{ app_id: string; private_key: File | null }>({
        app_id: enableBanking.appId ?? '',
        private_key: null,
    });

    const enableBankingStatus = enableBanking.configured
        ? enableBanking.source === 'database'
            ? 'Configured via stored credentials'
            : 'Configured via environment'
        : 'Not configured';

    return (
        <>
            <Head title="Platform settings" />

            <div className="space-y-6 px-4 py-6">
                <Heading
                    title="Platform settings"
                    description="Instance-wide configuration for this Freyr installation"
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Registration</CardTitle>
                        <CardDescription>
                            Allow other people to sign up on this instance
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                registration.patch(updateRegistration.url(), {
                                    preserveScroll: true,
                                });
                            }}
                            className="flex items-center gap-3"
                        >
                            <Checkbox
                                id="enabled"
                                checked={registration.data.enabled}
                                onCheckedChange={(checked) =>
                                    registration.setData(
                                        'enabled',
                                        checked === true,
                                    )
                                }
                            />
                            <Label htmlFor="enabled">
                                Registration enabled
                            </Label>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={registration.processing}
                            >
                                {registration.processing && <Spinner />}
                                Save
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Enable Banking</CardTitle>
                        <CardDescription>
                            {enableBankingStatus}
                            {enableBanking.configured && enableBanking.appId
                                ? ` — ${enableBanking.appId}`
                                : ''}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                credentials.put(updateEnableBanking.url(), {
                                    preserveScroll: true,
                                    forceFormData: true,
                                });
                            }}
                            className="grid gap-4"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="app_id">Application ID</Label>
                                <Input
                                    id="app_id"
                                    type="text"
                                    required
                                    value={credentials.data.app_id}
                                    onChange={(event) =>
                                        credentials.setData(
                                            'app_id',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={credentials.errors.app_id}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="private_key">
                                    Private key (.pem)
                                </Label>
                                <Input
                                    id="private_key"
                                    type="file"
                                    required
                                    accept=".pem,.key,.txt"
                                    onChange={(event) =>
                                        credentials.setData(
                                            'private_key',
                                            event.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                <InputError
                                    message={credentials.errors.private_key}
                                />
                            </div>
                            <div className="flex items-center gap-3">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={credentials.processing}
                                >
                                    {credentials.processing && <Spinner />}
                                    Save credentials
                                </Button>
                                {enableBanking.source === 'database' && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="destructive"
                                        onClick={() =>
                                            router.delete(
                                                destroyEnableBanking.url(),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Remove stored credentials
                                    </Button>
                                )}
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {spiir.canImport && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Spiir import</CardTitle>
                            <CardDescription>
                                Import accounts and postings from a Spiir export
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <SpiirImportForm
                                action={storeSpiirImport.form()}
                                canImport={spiir.canImport}
                                hasDefaultCategories={
                                    spiir.hasDefaultCategories
                                }
                                initialImport={initialImport}
                            />
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

PlatformSettingsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Settings',
            href: settingsEdit(),
        } satisfies BreadcrumbItem,
    ],
};
