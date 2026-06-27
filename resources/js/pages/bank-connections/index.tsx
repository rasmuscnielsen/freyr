import { Head, router } from '@inertiajs/react';
import {
    Link2,
    RefreshCw,
    ShieldAlert,
    Unplug,
    WalletCards,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    store as storeConnection,
    renew as renewConnection,
} from '@/routes/bank-connections';
import {
    update as updateConnectionAccount,
    sync as syncConnectionAccount,
} from '@/routes/bank-connection-accounts';

type LocalBankAccount = {
    id: number;
    name: string;
    bank_name: string | null;
    currency: string;
};

type RemoteAccount = {
    id: number;
    status: string;
    name: string;
    details: string | null;
    currency: string;
    account_number_last_four: string | null;
    product: string | null;
    last_synced_at: string | null;
    last_transaction_date: string | null;
    bank_account: { id: number; name: string } | null;
};

type SyncRun = {
    id: number;
    status: string;
    date_from: string | null;
    date_to: string | null;
    new_postings_count: number;
    updated_postings_count: number;
    duplicate_postings_count: number;
    error_code: string | null;
    error_message: string | null;
    started_at: string | null;
};

type Connection = {
    id: number;
    status: string;
    aspsp_name: string;
    aspsp_country: string;
    access_valid_until: string | null;
    last_successful_sync_at: string | null;
    last_failed_sync_at: string | null;
    failure_code: string | null;
    failure_message: string | null;
    needs_renewal: boolean;
    accounts: RemoteAccount[];
    sync_runs: SyncRun[];
};

type Props = {
    connections: Connection[];
    bankAccounts: LocalBankAccount[];
    defaults: {
        aspsp_name: string;
        aspsp_country: string;
    };
};

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (['failed', 'renewal_required'].includes(status)) {
        return 'destructive';
    }

    if (['pending', 'unlinked'].includes(status)) {
        return 'secondary';
    }

    if (status === 'ignored') {
        return 'outline';
    }

    return 'default';
}

export default function BankConnections({
    connections,
    bankAccounts,
    defaults,
}: Props) {
    const [aspspName, setAspspName] = useState(defaults.aspsp_name);
    const [aspspCountry, setAspspCountry] = useState(defaults.aspsp_country);
    const [selectedAccounts, setSelectedAccounts] = useState<
        Record<number, string>
    >({});
    const [createNames, setCreateNames] = useState<Record<number, string>>({});

    const connect = () => {
        router.post(storeConnection.url(), {
            aspsp_name: aspspName,
            aspsp_country: aspspCountry,
        });
    };

    const linkExisting = (account: RemoteAccount) => {
        router.patch(updateConnectionAccount.url(account.id), {
            mode: 'existing',
            bank_account_id: selectedAccounts[account.id],
        });
    };

    const createAndLink = (account: RemoteAccount) => {
        router.patch(updateConnectionAccount.url(account.id), {
            mode: 'create',
            name: createNames[account.id] || account.details || account.name,
        });
    };

    const ignore = (account: RemoteAccount) => {
        router.patch(updateConnectionAccount.url(account.id), {
            mode: 'ignore',
        });
    };

    const sync = (account: RemoteAccount) => {
        router.post(syncConnectionAccount.url(account.id));
    };

    const renew = (connection: Connection) => {
        router.post(renewConnection.url(connection.id));
    };

    return (
        <>
            <Head title="Bank connections" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Bank connections
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Connect banks through Enable Banking and link remote
                            accounts to local accounts.
                        </p>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Connect bank</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-[1fr_120px_auto] md:items-end">
                        <div className="grid gap-2">
                            <Label htmlFor="aspsp_name">Bank</Label>
                            <Input
                                id="aspsp_name"
                                value={aspspName}
                                onChange={(event) =>
                                    setAspspName(event.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="aspsp_country">Country</Label>
                            <Input
                                id="aspsp_country"
                                value={aspspCountry}
                                maxLength={2}
                                onChange={(event) =>
                                    setAspspCountry(
                                        event.target.value.toUpperCase(),
                                    )
                                }
                            />
                        </div>
                        <Button onClick={connect}>
                            <Link2 className="size-4" />
                            Connect
                        </Button>
                    </CardContent>
                </Card>

                {connections.map((connection) => (
                    <Card key={connection.id}>
                        <CardHeader>
                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <CardTitle className="flex items-center gap-2">
                                    <WalletCards className="size-5" />
                                    {connection.aspsp_name} (
                                    {connection.aspsp_country})
                                </CardTitle>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge
                                        variant={statusVariant(
                                            connection.status,
                                        )}
                                    >
                                        {connection.status.replace('_', ' ')}
                                    </Badge>
                                    {connection.needs_renewal && (
                                        <Button
                                            variant="outline"
                                            onClick={() => renew(connection)}
                                        >
                                            <RefreshCw className="size-4" />
                                            Renew
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            {(connection.failure_code ||
                                connection.failure_message) && (
                                <div className="flex gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                                    <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                                    <div>
                                        <div className="font-medium">
                                            {connection.failure_code ??
                                                'Connection failed'}
                                        </div>
                                        <div>{connection.failure_message}</div>
                                    </div>
                                </div>
                            )}

                            <div className="grid gap-3">
                                {connection.accounts.map((account) => (
                                    <div
                                        key={account.id}
                                        className="grid gap-3 rounded-md border p-3 lg:grid-cols-[1fr_360px_auto] lg:items-center"
                                    >
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="font-medium">
                                                    {account.details ??
                                                        account.name}
                                                </h3>
                                                <Badge
                                                    variant={statusVariant(
                                                        account.status,
                                                    )}
                                                >
                                                    {account.status.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                {account.product ?? 'Account'} ·{' '}
                                                {account.currency}
                                                {account.account_number_last_four
                                                    ? ` · *${account.account_number_last_four}`
                                                    : ''}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                Linked to:{' '}
                                                {account.bank_account?.name ??
                                                    'Not linked'}{' '}
                                                · Last transaction:{' '}
                                                {account.last_transaction_date ??
                                                    'Never'}
                                            </p>
                                        </div>

                                        <div className="grid gap-2">
                                            <select
                                                className="h-9 rounded-md border bg-background px-3 text-sm"
                                                value={
                                                    selectedAccounts[
                                                        account.id
                                                    ] ?? ''
                                                }
                                                onChange={(event) =>
                                                    setSelectedAccounts({
                                                        ...selectedAccounts,
                                                        [account.id]:
                                                            event.target.value,
                                                    })
                                                }
                                            >
                                                <option value="">
                                                    Choose local account
                                                </option>
                                                {bankAccounts.map(
                                                    (bankAccount) => (
                                                        <option
                                                            key={bankAccount.id}
                                                            value={
                                                                bankAccount.id
                                                            }
                                                        >
                                                            {bankAccount.bank_name
                                                                ? `${bankAccount.bank_name} · ${bankAccount.name}`
                                                                : bankAccount.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <Input
                                                placeholder={
                                                    account.details ??
                                                    account.name
                                                }
                                                value={
                                                    createNames[account.id] ??
                                                    ''
                                                }
                                                onChange={(event) =>
                                                    setCreateNames({
                                                        ...createNames,
                                                        [account.id]:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="flex flex-wrap gap-2 lg:justify-end">
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    linkExisting(account)
                                                }
                                                disabled={
                                                    !selectedAccounts[
                                                        account.id
                                                    ]
                                                }
                                            >
                                                Link
                                            </Button>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    createAndLink(account)
                                                }
                                            >
                                                Create
                                            </Button>
                                            <Button
                                                onClick={() => sync(account)}
                                                disabled={
                                                    account.bank_account ===
                                                    null
                                                }
                                            >
                                                <RefreshCw className="size-4" />
                                                Sync
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                onClick={() => ignore(account)}
                                            >
                                                <Unplug className="size-4" />
                                                Ignore
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {connection.sync_runs.length > 0 && (
                                <div className="space-y-2">
                                    <h3 className="text-sm font-medium">
                                        Recent syncs
                                    </h3>
                                    <div className="grid gap-2">
                                        {connection.sync_runs.map((run) => (
                                            <div
                                                key={run.id}
                                                className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted px-3 py-2 text-sm"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Badge
                                                        variant={statusVariant(
                                                            run.status,
                                                        )}
                                                    >
                                                        {run.status}
                                                    </Badge>
                                                    <span>
                                                        {run.date_from ?? '?'}{' '}
                                                        to {run.date_to ?? '?'}
                                                    </span>
                                                </div>
                                                <span className="text-muted-foreground">
                                                    +{run.new_postings_count} /
                                                    updated{' '}
                                                    {run.updated_postings_count}{' '}
                                                    / duplicate{' '}
                                                    {
                                                        run.duplicate_postings_count
                                                    }
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ))}

                {connections.length === 0 && (
                    <div className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No bank connections yet.
                    </div>
                )}
            </div>
        </>
    );
}
