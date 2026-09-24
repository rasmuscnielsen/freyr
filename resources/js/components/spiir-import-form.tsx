import { Form } from '@inertiajs/react';
import {
    CircleAlertIcon,
    CircleCheckIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useSpiirImportStatus } from '@/hooks/use-spiir-import-status';
import type { SpiirImportStatus } from '@/hooks/use-spiir-import-status';
import type { RouteFormDefinition } from '@/wayfinder';

const files = [
    {
        name: 'categories',
        label: 'Categories',
        hint: 'Categories.json',
    },
    { name: 'user', label: 'User', hint: 'User.csv' },
    { name: 'accounts', label: 'Accounts', hint: 'Account.csv' },
    { name: 'postings', label: 'Postings', hint: 'Posting.csv' },
] as const;

export default function SpiirImportForm({
    action,
    canImport,
    hasDefaultCategories,
    initialImport,
    onStatusChange,
}: {
    action: RouteFormDefinition<'post'>;
    canImport: boolean;
    hasDefaultCategories: boolean;
    initialImport: SpiirImportStatus | null;
    onStatusChange?: (status: SpiirImportStatus) => void;
}) {
    const status = useSpiirImportStatus(initialImport);

    useEffect(() => {
        if (status) {
            onStatusChange?.(status);
        }
    }, [status, onStatusChange]);

    if (!canImport && !status) {
        return (
            <Alert>
                <CircleAlertIcon />
                <AlertTitle>Spiir import unavailable</AlertTitle>
                <AlertDescription>
                    Spiir import is only available before any postings exist.
                </AlertDescription>
            </Alert>
        );
    }

    if (status?.status === 'pending' || status?.status === 'running') {
        return (
            <div className="flex items-center gap-3 text-sm text-muted-foreground">
                <Spinner />
                Importing your Spiir export…
            </div>
        );
    }

    if (status?.status === 'completed') {
        return (
            <Alert>
                <CircleCheckIcon />
                <AlertTitle>Import complete</AlertTitle>
                <AlertDescription>
                    <p>
                        Imported {status.summary?.accounts ?? 0} accounts,{' '}
                        {status.summary?.postings ?? 0} postings and{' '}
                        {status.summary?.counter_entries ?? 0} counter entries.
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            {hasDefaultCategories && (
                <Alert>
                    <TriangleAlertIcon />
                    <AlertTitle>Categories will be replaced</AlertTitle>
                    <AlertDescription>
                        Your Spiir categories will replace the default category
                        tree.
                    </AlertDescription>
                </Alert>
            )}

            {status?.status === 'failed' && (
                <Alert variant="destructive">
                    <CircleAlertIcon />
                    <AlertTitle>Import failed</AlertTitle>
                    <AlertDescription>
                        {status.error ?? 'The import could not be completed.'}
                    </AlertDescription>
                </Alert>
            )}

            <p className="text-sm text-muted-foreground">
                Export your data from Spiir and upload the four files below. The
                import runs in the background and usually finishes within a
                minute.
            </p>

            <Form {...action} className="flex flex-col gap-6">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-4">
                            {files.map((file) => (
                                <div key={file.name} className="grid gap-2">
                                    <Label htmlFor={file.name}>
                                        {file.label}{' '}
                                        <span className="text-muted-foreground">
                                            ({file.hint})
                                        </span>
                                    </Label>
                                    <Input
                                        id={file.name}
                                        type="file"
                                        name={file.name}
                                        required
                                    />
                                    <InputError message={errors[file.name]} />
                                </div>
                            ))}
                        </div>

                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="spiir-import-button"
                        >
                            {processing && <Spinner />}
                            Import Spiir export
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}
