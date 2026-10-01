import { Form, Head, router, useForm } from '@inertiajs/react';
import { CircleCheckIcon } from 'lucide-react';
import { useState } from 'react';
import {
    EnableBankingFields,
    EnableBankingIntro,
} from '@/components/enable-banking-credentials';
import Heading from '@/components/heading';
import SpiirImportForm from '@/components/spiir-import-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
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
    const [credentialsDialogOpen, setCredentialsDialogOpen] = useState(false);

    const toggleRegistration = (enabled: boolean) => {
        registration.setData('enabled', enabled);
        registration.transform(() => ({ enabled }));
        registration.patch(updateRegistration.url(), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Platform settings" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Registration"
                    description="Let other people create their own account on this Freyr installation"
                />

                <div className="space-y-3">
                    <div className="flex items-center gap-3">
                        <Switch
                            id="registration-enabled"
                            checked={registration.data.enabled}
                            onCheckedChange={toggleRegistration}
                            disabled={registration.processing}
                        />
                        <Label htmlFor="registration-enabled">
                            Allow registration
                        </Label>
                        {registration.processing && <Spinner />}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Most Freyr installations are personal, so you can safely
                        keep this off. Anyone who registers can connect bank
                        accounts through your Enable Banking subscription —
                        which is billed per connected account unless you
                        whitelist the bank accounts allowed to connect.
                    </p>
                </div>
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Enable Banking"
                    description="Connects this installation to banks so accounts and transactions sync automatically"
                />

                {enableBanking.configured ? (
                    <div className="flex items-center justify-between gap-4">
                        <div className="flex items-center gap-3">
                            <CircleCheckIcon className="size-4 text-green-600 dark:text-green-500" />
                            <div>
                                <p className="text-sm font-medium">
                                    Configured
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {enableBanking.appId
                                        ? `Application ${enableBanking.appId}`
                                        : 'Credentials are set'}
                                    {enableBanking.source === 'environment' &&
                                        ' — set via environment variables'}
                                </p>
                            </div>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setCredentialsDialogOpen(true)}
                        >
                            Update credentials
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Not configured — bank connections are unavailable
                            until credentials are set up.
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setCredentialsDialogOpen(true)}
                        >
                            Set up Enable Banking
                        </Button>
                    </div>
                )}

                <Dialog
                    open={credentialsDialogOpen}
                    onOpenChange={setCredentialsDialogOpen}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                Enable Banking credentials
                            </DialogTitle>
                            <DialogDescription>
                                Instance-wide credentials used to connect bank
                                accounts.
                            </DialogDescription>
                        </DialogHeader>

                        <EnableBankingIntro />

                        <Form
                            {...updateEnableBanking.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onSuccess={() => setCredentialsDialogOpen(false)}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <EnableBankingFields
                                        appId={enableBanking.appId}
                                        errors={errors}
                                    />
                                    <DialogFooter>
                                        {enableBanking.source ===
                                            'database' && (
                                            <Button
                                                type="button"
                                                variant="destructive"
                                                onClick={() =>
                                                    router.delete(
                                                        destroyEnableBanking.url(),
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () =>
                                                                setCredentialsDialogOpen(
                                                                    false,
                                                                ),
                                                        },
                                                    )
                                                }
                                            >
                                                Remove credentials
                                            </Button>
                                        )}
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Save credentials
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>

            {spiir.canImport && (
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Spiir import"
                        description="Import accounts and postings from a Spiir export"
                    />
                    <SpiirImportForm
                        action={storeSpiirImport.form()}
                        canImport={spiir.canImport}
                        hasDefaultCategories={spiir.hasDefaultCategories}
                        initialImport={initialImport}
                    />
                </div>
            )}
        </>
    );
}

PlatformSettingsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Platform settings',
            href: settingsEdit(),
        } satisfies BreadcrumbItem,
    ],
};
