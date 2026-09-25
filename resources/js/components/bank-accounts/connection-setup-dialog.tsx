import { router, useForm } from '@inertiajs/react';
import { Landmark, MoreVertical, Unplug } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { update as updateConnectionAccount } from '@/routes/bank-connection-accounts';
import { map as mapAccounts } from '@/routes/bank-connections/accounts';

export type SetupConnectionAccount = {
    id: number;
    status: string;
    name: string;
    currency: string;
    product: string | null;
    account_number_last_four: string | null;
    last_synced_at: string | null;
    bank_account: { id: number; name: string } | null;
};

export type SetupConnection = {
    id: number;
    aspsp_name: string;
    accounts: SetupConnectionAccount[];
};

export type LinkableAccount = {
    id: number;
    name: string;
    bank_name: string | null;
    currency: string;
};

type RowMode = '' | 'create' | 'existing' | 'ignore';

type HistoryOption = '1y' | '2y' | 'max';

type RowState = {
    mode: RowMode;
    name: string;
    bankAccountId: string;
    history: HistoryOption;
};

function formatDateTime(value: string | null): string {
    if (value === null) {
        return 'Not synced';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value.replace(' ', 'T')));
}

export function ConnectionSetupDialog({
    connection,
    linkableAccounts,
    isSetup,
    onClose,
}: {
    connection: SetupConnection | null;
    linkableAccounts: LinkableAccount[];
    isSetup: boolean;
    onClose: () => void;
}) {
    const [rows, setRows] = useState<Record<number, RowState>>(() =>
        Object.fromEntries(
            (connection?.accounts ?? [])
                .filter(
                    (account) =>
                        account.status === 'unlinked' ||
                        account.status === 'ignored',
                )
                .map((account) => [
                    account.id,
                    {
                        mode:
                            linkableAccounts.length === 0 &&
                            account.status !== 'ignored'
                                ? 'create'
                                : '',
                        name: account.name,
                        bankAccountId: '',
                        history: '1y' as HistoryOption,
                    },
                ]),
        ),
    );
    const [stoppingAccount, setStoppingAccount] =
        useState<SetupConnectionAccount | null>(null);

    const form = useForm<{
        accounts: Array<{
            id: number;
            mode: string;
            name: string | null;
            bank_account_id: number | null;
            history: string | null;
        }>;
    }>({ accounts: [] });

    const accounts = connection?.accounts ?? [];
    const pendingAccounts = accounts.filter(
        (account) =>
            account.status === 'unlinked' || account.status === 'ignored',
    );

    const rowState = (account: SetupConnectionAccount): RowState =>
        rows[account.id] ?? {
            mode: '',
            name: account.name,
            bankAccountId: '',
            history: '1y',
        };

    const setRow = (id: number, patch: Partial<RowState>) => {
        setRows((current) => ({
            ...current,
            [id]: {
                ...(current[id] ?? {
                    mode: '',
                    name: '',
                    bankAccountId: '',
                    history: '1y',
                }),
                ...patch,
            },
        }));
    };

    // Local accounts already picked by another row cannot be picked twice.
    const chosenAccountIds = new Set(
        pendingAccounts
            .map((account) => rowState(account))
            .filter(
                (row) => row.mode === 'existing' && row.bankAccountId !== '',
            )
            .map((row) => row.bankAccountId),
    );

    const isRowValid = (account: SetupConnectionAccount): boolean => {
        const row = rowState(account);

        if (row.mode === 'existing' && row.bankAccountId === '') {
            return false;
        }

        if (row.mode === 'create' && row.name.trim() === '') {
            return false;
        }

        return true;
    };

    // Only rows with a chosen action are posted; empty rows are left as is.
    // In setup mode every unlinked account needs an action, while already
    // ignored rows may stay untouched.
    const submittableAccounts = pendingAccounts.filter(
        (account) => rowState(account).mode !== '',
    );

    const hasEmptyUnlinked =
        isSetup &&
        pendingAccounts.some(
            (account) =>
                account.status === 'unlinked' && rowState(account).mode === '',
        );

    const canSubmit =
        !form.processing &&
        connection !== null &&
        !hasEmptyUnlinked &&
        submittableAccounts.every(isRowValid) &&
        (isSetup || submittableAccounts.length > 0);

    const submit = () => {
        if (connection === null || !canSubmit) {
            return;
        }

        form.transform(() => ({
            accounts: submittableAccounts.map((account) => {
                const row = rowState(account);

                return {
                    id: account.id,
                    mode: row.mode,
                    name: row.mode === 'create' ? row.name.trim() : null,
                    bank_account_id:
                        row.mode === 'existing'
                            ? Number(row.bankAccountId)
                            : null,
                    history: row.mode === 'ignore' ? null : row.history,
                };
            }),
        }));

        form.post(mapAccounts.url(connection.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const stopSyncing = () => {
        if (stoppingAccount === null) {
            return;
        }

        router.patch(
            updateConnectionAccount.url(stoppingAccount.id),
            { mode: 'ignore' },
            {
                preserveScroll: true,
                onSuccess: () => setStoppingAccount(null),
            },
        );
    };

    const syncingCount = submittableAccounts.filter((account) => {
        const mode = rowState(account).mode;

        return mode === 'create' || mode === 'existing';
    }).length;

    return (
        <>
            <Dialog
                open={connection !== null}
                onOpenChange={(nextOpen) => !nextOpen && onClose()}
            >
                <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {isSetup
                                ? `Set up ${connection?.aspsp_name ?? 'bank'}`
                                : `Manage ${connection?.aspsp_name ?? 'bank'}`}
                        </DialogTitle>
                        <DialogDescription>
                            {isSetup
                                ? 'Choose what to do with each account the bank returned. You can change this later.'
                                : `Accounts returned by ${connection?.aspsp_name ?? 'the bank'}.`}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid grid-cols-1 gap-3">
                        {accounts.length === 0 ? (
                            <Alert>
                                <Landmark className="size-4" />
                                <AlertTitle>No accounts returned</AlertTitle>
                                <AlertDescription>
                                    This connection has not returned any
                                    accounts yet.
                                </AlertDescription>
                            </Alert>
                        ) : (
                            accounts.map((account) => (
                                <SetupRow
                                    key={account.id}
                                    account={account}
                                    row={rowState(account)}
                                    linkableAccounts={linkableAccounts}
                                    takenAccountIds={chosenAccountIds}
                                    errorPrefix={`accounts.${submittableAccounts.indexOf(account)}`}
                                    errors={form.errors}
                                    onChange={(patch) =>
                                        setRow(account.id, patch)
                                    }
                                    onStopSyncing={() =>
                                        setStoppingAccount(account)
                                    }
                                />
                            ))
                        )}
                    </div>

                    {isSetup && pendingAccounts.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            {syncingCount} of {pendingAccounts.length} accounts
                            will start syncing
                        </p>
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={onClose}>
                            {isSetup ? 'Cancel' : 'Close'}
                        </Button>
                        {pendingAccounts.length > 0 ? (
                            <Button onClick={submit} disabled={!canSubmit}>
                                {isSetup ? 'Finish setup' : 'Save changes'}
                            </Button>
                        ) : (
                            <Button onClick={onClose}>Done</Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={stoppingAccount !== null}
                onOpenChange={(nextOpen) =>
                    !nextOpen && setStoppingAccount(null)
                }
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            Stop syncing {stoppingAccount?.name}?
                        </DialogTitle>
                        <DialogDescription>
                            The local account{' '}
                            {stoppingAccount?.bank_account?.name} is kept with
                            its postings and becomes a manual account. You can
                            link it again later.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setStoppingAccount(null)}
                        >
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={stopSyncing}>
                            <Unplug className="size-4" />
                            Stop syncing
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function SetupRow({
    account,
    row,
    linkableAccounts,
    takenAccountIds,
    errorPrefix,
    errors,
    onChange,
    onStopSyncing,
}: {
    account: SetupConnectionAccount;
    row: RowState;
    linkableAccounts: LinkableAccount[];
    takenAccountIds: Set<string>;
    errorPrefix: string;
    errors: Record<string, string | undefined>;
    onChange: (patch: Partial<RowState>) => void;
    onStopSyncing: () => void;
}) {
    const isLinked =
        account.status === 'linked' && account.bank_account !== null;
    const isIgnored = account.status === 'ignored';

    return (
        <div className="grid grid-cols-1 gap-3 rounded-lg border p-3">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted">
                        <Landmark className="size-4 text-muted-foreground" />
                    </div>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <span className="truncate font-semibold">
                                {account.name}
                            </span>
                            {isIgnored && (
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    Ignored
                                </span>
                            )}
                        </div>
                        <p className="truncate text-sm text-muted-foreground">
                            {account.product ?? 'Account'} · {account.currency}
                            {account.account_number_last_four
                                ? ` · *${account.account_number_last_four}`
                                : ''}
                        </p>
                    </div>
                </div>

                {isLinked ? (
                    <div className="flex shrink-0 items-start gap-2 self-end sm:self-start">
                        <div className="text-right">
                            <div className="flex items-center justify-end gap-1.5 text-sm">
                                <span
                                    className="size-1.5 rounded-full bg-emerald-500"
                                    aria-hidden
                                />
                                Synced to {account.bank_account?.name}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                Last sync{' '}
                                {formatDateTime(account.last_synced_at)}
                            </div>
                        </div>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Actions for ${account.name}`}
                                >
                                    <MoreVertical className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={onStopSyncing}
                                >
                                    <Unplug className="size-4" />
                                    Stop syncing
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                ) : (
                    <Select
                        value={row.mode}
                        onValueChange={(value) =>
                            onChange({ mode: value as RowMode })
                        }
                    >
                        <SelectTrigger className="w-full shrink-0 sm:w-56">
                            <SelectValue placeholder="Choose action…" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="create">
                                Create new account
                            </SelectItem>
                            {linkableAccounts.length > 0 && (
                                <SelectItem value="existing">
                                    Link to existing account
                                </SelectItem>
                            )}
                            <SelectItem value="ignore">Ignore</SelectItem>
                        </SelectContent>
                    </Select>
                )}
            </div>

            {!isLinked && row.mode === 'create' && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 items-start">
                    <div className="grid gap-2">
                        <Label htmlFor={`setup-name-${account.id}`}>
                            Account name
                        </Label>
                        <Input
                            id={`setup-name-${account.id}`}
                            value={row.name}
                            onChange={(event) =>
                                onChange({ name: event.target.value })
                            }
                        />
                        <InputError message={errors[`${errorPrefix}.name`]} />
                    </div>
                    <HistorySelect
                        id={`setup-history-${account.id}`}
                        value={row.history}
                        onChange={(value) => onChange({ history: value })}
                    />
                </div>
            )}

            {!isLinked && row.mode === 'existing' && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label>Choose account</Label>
                        <Select
                            value={row.bankAccountId}
                            onValueChange={(value) =>
                                onChange({ bankAccountId: value })
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Choose account…" />
                            </SelectTrigger>
                            <SelectContent>
                                {linkableAccounts.map((localAccount) => (
                                    <SelectItem
                                        key={localAccount.id}
                                        value={String(localAccount.id)}
                                        disabled={
                                            takenAccountIds.has(
                                                String(localAccount.id),
                                            ) &&
                                            row.bankAccountId !==
                                                String(localAccount.id)
                                        }
                                    >
                                        {localAccount.name} ·{' '}
                                        {localAccount.currency}
                                        {localAccount.bank_name
                                            ? ` · ${localAccount.bank_name}`
                                            : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            message={errors[`${errorPrefix}.bank_account_id`]}
                        />
                    </div>
                    <HistorySelect
                        id={`setup-history-${account.id}`}
                        value={row.history}
                        onChange={(value) => onChange({ history: value })}
                    />
                </div>
            )}
        </div>
    );
}

function HistorySelect({
    id,
    value,
    onChange,
}: {
    id: string;
    value: HistoryOption;
    onChange: (value: HistoryOption) => void;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Import transaction history</Label>
            <Select
                value={value}
                onValueChange={(next) => onChange(next as HistoryOption)}
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="1y">1 year</SelectItem>
                    <SelectItem value="2y">2 years</SelectItem>
                    <SelectItem value="max">Max</SelectItem>
                </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
                Some banks may limit transaction history.
            </p>
        </div>
    );
}
