import { Head, router, useForm } from '@inertiajs/react';
import {
    Archive,
    CheckCircle2,
    ChevronRight,
    Landmark,
    MoreVertical,
    Pencil,
    Plus,
    RefreshCw,
    RotateCcw,
    Star,
    TriangleAlert,
    Trash2,
    WalletCards,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { KeyboardEvent, MouseEvent } from 'react';
import { toast } from 'sonner';
import { ConnectionSetupDialog } from '@/components/bank-accounts/connection-setup-dialog';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
    DropdownMenuSeparator,
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
import { formatDate } from '@/lib/dates';
import { formatMoney as formatSharedMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import {
    index as bankAccountsIndex,
    show as showAccount,
    store as storeAccount,
    update as updateAccount,
} from '@/routes/bank-accounts';
import { update as updateConnectionAccount } from '@/routes/bank-connection-accounts';
import {
    destroy as destroyConnection,
    renew as renewConnection,
    store as storeConnection,
    sync as syncConnection,
} from '@/routes/bank-connections';

type ConnectionSummary = {
    id: number;
    status: string;
    aspsp_name: string;
    aspsp_country: string;
    needs_renewal: boolean;
};

type LinkedConnectionAccount = {
    id: number;
    status: string;
    name: string;
    account_number_last_four: string | null;
    last_synced_at: string | null;
    last_transaction_date: string | null;
    needs_renewal: boolean;
    connection: ConnectionSummary;
};

type Account = {
    id: number;
    name: string;
    bank_key: string | null;
    bank_name: string | null;
    currency: string;
    current_balance: number | null;
    account_type: string | null;
    account_subcategory_key: string | null;
    last_known_posting_date: string | null;
    is_active: boolean;
    is_favorite: boolean;
    postings_count: number;
    postings_max_date: string | null;
    connection_accounts: LinkedConnectionAccount[];
};

type LinkableConnectionAccount = {
    id: number;
    status: string;
    name: string;
    details: string | null;
    currency: string;
    account_number_last_four: string | null;
    product: string | null;
    connection: {
        id: number;
        aspsp_name: string;
        aspsp_country: string;
    };
};

type ConnectionAccount = {
    id: number;
    status: string;
    name: string;
    currency: string;
    product: string | null;
    account_number_last_four: string | null;
    last_synced_at: string | null;
    last_transaction_date: string | null;
    bank_account: { id: number; name: string } | null;
    details?: string | null;
};

type Connection = {
    id: number;
    status: string;
    aspsp_name: string;
    aspsp_country: string;
    access_valid_until: string | null;
    last_successful_sync_at: string | null;
    failure_code: string | null;
    failure_message: string | null;
    needs_renewal: boolean;
    linked_accounts_count: number;
    balance_mismatch: { delta: string; currency: string | null } | null;
    accounts: ConnectionAccount[];
};

type AvailableBank = {
    key: string;
    name: string;
    country: string;
};

type AccountForm = {
    name: string;
    bank_name: string;
    currency: string;
    account_type: string;
    last_known_posting_date: string;
    is_active: boolean;
    is_favorite: boolean;
};

type BankAccountsProps = {
    accounts: Account[];
    linkableConnectionAccounts: LinkableConnectionAccount[];
    connections: Connection[];
    availableBanks: AvailableBank[];
    defaults: {
        aspsp_name: string | null;
        aspsp_country: string | null;
    };
    setupConnectionId: number | null;
};

const accountTypeOptions = [
    'Consumption',
    'Savings',
    'Loan',
    'Investment',
    'Pension',
    'Other',
];

const emptyForm: AccountForm = {
    name: '',
    bank_name: '',
    currency: '',
    account_type: 'Consumption',
    last_known_posting_date: '',
    is_active: true,
    is_favorite: false,
};

function accountToForm(account: Account): AccountForm {
    return {
        name: account.name,
        bank_name: account.bank_name ?? '',
        currency: account.currency,
        account_type: account.account_type ?? 'Other',
        last_known_posting_date: account.last_known_posting_date ?? '',
        is_active: account.is_active,
        is_favorite: account.is_favorite,
    };
}

function cleanPayload(form: AccountForm) {
    return {
        ...form,
        bank_name: form.bank_name || null,
        account_type: form.account_type || null,
        last_known_posting_date: form.last_known_posting_date || null,
    };
}

function formatMoney(amount: number | null, currency: string): string {
    if (amount === null) {
        return 'Not synced';
    }

    return formatSharedMoney(amount, currency, { maximumFractionDigits: 0 });
}

function formatDateTime(value: string | null): string {
    if (value === null) {
        return 'Not synced';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value.replace(' ', 'T')));
}

function daysUntil(value: string | null): number | null {
    if (value === null) {
        return null;
    }

    const diff = new Date(value).getTime() - Date.now();

    return Math.floor(diff / (1000 * 60 * 60 * 24));
}

function accountHref(account: Account) {
    return showAccount(account.id);
}

const accountGroupOrder = [
    'Consumption',
    'Savings',
    'Loan',
    'Investment',
    'Pension',
];

const accountGroupLabels: Record<string, string> = {
    Consumption: 'Spending',
    Savings: 'Savings',
    Loan: 'Loans',
    Investment: 'Investments',
    Pension: 'Pensions',
};

type AccountGroup = {
    label: string;
    accounts: Account[];
};

function groupAccountsByType(accounts: Account[]): AccountGroup[] {
    const byType = new Map<string, Account[]>();

    for (const account of accounts) {
        const key = account.account_type ?? 'Other';
        byType.set(key, [...(byType.get(key) ?? []), account]);
    }

    const orderedKeys = [
        ...accountGroupOrder.filter((key) => byType.has(key)),
        ...[...byType.keys()]
            .filter((key) => !accountGroupOrder.includes(key))
            .sort(),
    ];

    return orderedKeys.map((key) => ({
        label: accountGroupLabels[key] ?? key,
        accounts: (byType.get(key) ?? []).sort(
            (a, b) =>
                Number(b.is_favorite) - Number(a.is_favorite) ||
                a.name.localeCompare(b.name),
        ),
    }));
}

function stopCardNavigation(event: MouseEvent) {
    event.stopPropagation();
}

/**
 * Sums account balances per currency, since aggregating across currencies is
 * not supported. Accounts without a known balance are skipped. Currencies are
 * ordered by how many accounts use them, so the primary one leads.
 */
function balancesByCurrency(
    accounts: Account[],
): Array<{ currency: string; total: number }> {
    const totals = new Map<string, { total: number; count: number }>();

    for (const account of accounts) {
        if (account.current_balance === null) {
            continue;
        }

        const entry = totals.get(account.currency) ?? { total: 0, count: 0 };
        entry.total += account.current_balance;
        entry.count += 1;
        totals.set(account.currency, entry);
    }

    return [...totals.entries()]
        .sort((a, b) => b[1].count - a[1].count)
        .map(([currency, entry]) => ({ currency, total: entry.total }));
}

export default function BankAccounts({
    accounts,
    linkableConnectionAccounts,
    connections,
    availableBanks,
    defaults,
    setupConnectionId,
}: BankAccountsProps) {
    const [editingAccount, setEditingAccount] = useState<Account | null>(null);
    const [isCreating, setIsCreating] = useState(false);
    const [connectingAccount, setConnectingAccount] = useState<Account | null>(
        null,
    );
    const [managingConnectionId, setManagingConnectionId] = useState<
        number | null
    >(() =>
        connections.some((connection) => connection.id === setupConnectionId)
            ? setupConnectionId
            : null,
    );
    const [isSetupMode, setIsSetupMode] = useState(
        () => managingConnectionId !== null,
    );
    // The dialog stays mounted across the setup post, so always render the
    // freshest connection object from props rather than stale state.
    const managingConnection =
        connections.find(
            (connection) => connection.id === managingConnectionId,
        ) ?? null;

    const closeSetupDialog = () => {
        setManagingConnectionId(null);

        if (setupConnectionId !== null) {
            // Strip ?setup= so a refresh does not reopen the wizard.
            router.get(
                bankAccountsIndex.url(),
                {},
                {
                    replace: true,
                    preserveScroll: true,
                    preserveState: true,
                },
            );
        } else {
            setIsSetupMode(false);
        }
    };
    const [removingConnection, setRemovingConnection] =
        useState<Connection | null>(null);
    const [addingConnection, setAddingConnection] = useState(false);

    const activeAccounts = accounts.filter((account) => account.is_active);
    const closedAccounts = accounts.filter((account) => !account.is_active);
    const accountGroups = groupAccountsByType(activeAccounts);
    const totalBalances = balancesByCurrency(activeAccounts);
    const hasNothing = accounts.length === 0 && connections.length === 0;

    const linkableAccounts = accounts.filter(
        (account) =>
            account.is_active && account.connection_accounts.length === 0,
    );

    return (
        <>
            <Head title="Bank accounts" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Bank accounts
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Accounts, bank connections, and posting access in
                            one place.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-end gap-x-6 gap-y-3">
                        {totalBalances.length > 0 && (
                            <div className="md:text-right">
                                <div className="text-xs text-muted-foreground">
                                    Total balance
                                </div>
                                <div className="flex flex-wrap items-baseline gap-x-3 font-semibold tabular-nums md:justify-end">
                                    {totalBalances.map((entry) => (
                                        <span key={entry.currency}>
                                            {formatMoney(
                                                entry.total,
                                                entry.currency,
                                            )}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}
                        <Button onClick={() => setIsCreating(true)}>
                            <Plus className="size-4" />
                            Add account
                        </Button>
                    </div>
                </div>

                {hasNothing ? (
                    <EmptyState
                        onAddBank={() => setAddingConnection(true)}
                        onAddAccount={() => setIsCreating(true)}
                    />
                ) : (
                    <div className="grid items-start gap-8 xl:grid-cols-[minmax(0,1fr)_340px]">
                        <div className="flex flex-col gap-6">
                            {accounts.length === 0 ? (
                                <div className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
                                    No bank accounts yet. Create one manually or
                                    connect a bank.
                                </div>
                            ) : (
                                <>
                                    {accountGroups.map((group) => (
                                        <AccountList
                                            key={group.label}
                                            label={group.label}
                                            accounts={group.accounts}
                                            onEdit={setEditingAccount}
                                            onConnect={setConnectingAccount}
                                        />
                                    ))}
                                    {closedAccounts.length > 0 && (
                                        <Collapsible
                                            defaultOpen={false}
                                            className="group"
                                        >
                                            <CollapsibleTrigger className="flex w-full items-center gap-2 rounded-md py-1 text-left">
                                                <ChevronRight className="size-4 text-muted-foreground transition-transform group-data-[state=open]:rotate-90" />
                                                <span className="text-sm font-semibold">
                                                    Closed
                                                </span>
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {closedAccounts.length}
                                                </span>
                                            </CollapsibleTrigger>
                                            <CollapsibleContent className="pt-2">
                                                <AccountList
                                                    label="Closed"
                                                    hideHeader
                                                    accounts={closedAccounts}
                                                    muted
                                                    onEdit={setEditingAccount}
                                                    onConnect={
                                                        setConnectingAccount
                                                    }
                                                />
                                            </CollapsibleContent>
                                        </Collapsible>
                                    )}
                                </>
                            )}
                        </div>

                        <BanksPanel
                            connections={connections}
                            onAddBank={() => setAddingConnection(true)}
                            onManage={(connection) => {
                                setIsSetupMode(false);
                                setManagingConnectionId(connection.id);
                            }}
                            onRemove={setRemovingConnection}
                        />
                    </div>
                )}
            </div>

            <AccountDialog
                key={
                    editingAccount === null
                        ? `account-dialog-${isCreating ? 'create' : 'closed'}`
                        : `account-dialog-${editingAccount.id}`
                }
                account={editingAccount}
                open={editingAccount !== null || isCreating}
                onClose={() => {
                    setEditingAccount(null);
                    setIsCreating(false);
                }}
            />

            <ConnectBankDialog
                key={
                    connectingAccount === null
                        ? 'connect-dialog-closed'
                        : `connect-dialog-${connectingAccount.id}`
                }
                account={connectingAccount}
                linkableConnectionAccounts={linkableConnectionAccounts}
                availableBanks={availableBanks}
                defaults={defaults}
                onClose={() => setConnectingAccount(null)}
            />

            <AddBankDialog
                open={addingConnection}
                availableBanks={availableBanks}
                defaults={defaults}
                onClose={() => setAddingConnection(false)}
            />

            <ConnectionSetupDialog
                key={
                    managingConnection === null
                        ? 'setup-dialog-closed'
                        : `setup-dialog-${managingConnection.id}`
                }
                connection={managingConnection}
                linkableAccounts={managingConnection ? linkableAccounts : []}
                isSetup={isSetupMode}
                onClose={closeSetupDialog}
            />

            <RemoveConnectionDialog
                connection={removingConnection}
                onClose={() => setRemovingConnection(null)}
            />
        </>
    );
}

function EmptyState({
    onAddBank,
    onAddAccount,
}: {
    onAddBank: () => void;
    onAddAccount: () => void;
}) {
    return (
        <div className="flex flex-col items-center gap-4 rounded-lg border border-dashed p-10 text-center">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                <WalletCards className="size-6 text-muted-foreground" />
            </div>
            <div className="space-y-1">
                <h2 className="text-lg font-semibold">No accounts yet</h2>
                <p className="mx-auto max-w-md text-sm text-muted-foreground">
                    Connect a bank to sync balances and postings automatically,
                    or create a manual account to track it yourself.
                </p>
            </div>
            <div className="flex flex-wrap justify-center gap-2">
                <Button onClick={onAddBank}>
                    <Landmark className="size-4" />
                    Connect a bank
                </Button>
                <Button variant="outline" onClick={onAddAccount}>
                    <Plus className="size-4" />
                    Add manual account
                </Button>
            </div>
        </div>
    );
}

function BanksPanel({
    connections,
    onAddBank,
    onManage,
    onRemove,
}: {
    connections: Connection[];
    onAddBank: () => void;
    onManage: (connection: Connection) => void;
    onRemove: (connection: Connection) => void;
}) {
    return (
        <aside className="flex flex-col gap-3 xl:sticky xl:top-6">
            <div className="flex items-center justify-between">
                <div className="flex items-baseline gap-2">
                    <h2 className="text-sm font-semibold">Connections</h2>
                    {connections.length > 0 && (
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {connections.length}
                        </span>
                    )}
                </div>
                <Button variant="outline" size="sm" onClick={onAddBank}>
                    <Plus className="size-4" />
                    Add bank
                </Button>
            </div>
            {connections.length === 0 ? (
                <div className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                    No banks connected yet.
                </div>
            ) : (
                connections.map((connection) => (
                    <ConnectionCard
                        key={connection.id}
                        connection={connection}
                        onManage={() => onManage(connection)}
                        onRemove={() => onRemove(connection)}
                    />
                ))
            )}
        </aside>
    );
}

function ConnectionCard({
    connection,
    onManage,
    onRemove,
}: {
    connection: Connection;
    onManage: () => void;
    onRemove: () => void;
}) {
    const expiryDays = daysUntil(connection.access_valid_until);
    const expired = expiryDays !== null && expiryDays < 0;
    const expiringSoon =
        expiryDays !== null && expiryDays >= 0 && expiryDays < 14;

    const expiryLabel = () => {
        if (connection.access_valid_until === null) {
            return 'No expiry set';
        }

        if (expired) {
            return 'Access expired';
        }

        return `Expires in ${expiryDays} ${expiryDays === 1 ? 'day' : 'days'}`;
    };

    // Bank consent windows are 90 days; show how much of it remains.
    const expiryFraction =
        expiryDays === null ? null : Math.max(0, Math.min(1, expiryDays / 90));
    const expiryBarClass = expired
        ? 'bg-destructive'
        : expiringSoon
          ? 'bg-destructive'
          : expiryDays !== null && expiryDays < 30
            ? 'bg-amber-400'
            : 'bg-emerald-500';

    const pendingCount = connection.accounts.filter(
        (account) => account.status === 'unlinked',
    ).length;

    const syncAll = () => {
        toast('Syncing bank accounts...');
        router.post(
            syncConnection.url(connection.id),
            {},
            { preserveScroll: true },
        );
    };

    const renew = () => {
        router.post(renewConnection.url(connection.id));
    };

    return (
        <Card className="py-0">
            <CardContent className="flex flex-col gap-4 p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand/10">
                            <Landmark className="size-4 text-brand" />
                        </div>
                        <div className="min-w-0">
                            <h3 className="truncate text-sm font-semibold">
                                {connection.aspsp_name}
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                {connection.aspsp_country} ·{' '}
                                {connection.linked_accounts_count} linked
                            </p>
                        </div>
                    </div>
                    <span className="flex shrink-0 items-center gap-1.5 pt-0.5 text-xs text-muted-foreground capitalize">
                        <span
                            className={cn(
                                'size-1.5 rounded-full',
                                connectionStatusDot(connection.status),
                            )}
                            aria-hidden
                        />
                        {connection.status.replace('_', ' ')}
                        {pendingCount > 0 && (
                            <span className="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 normal-case dark:bg-amber-950 dark:text-amber-400">
                                {pendingCount} to set up
                            </span>
                        )}
                    </span>
                </div>

                <div className="grid gap-1.5">
                    <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 text-xs">
                        <span
                            className={
                                expired || expiringSoon
                                    ? 'font-medium text-destructive'
                                    : 'text-muted-foreground'
                            }
                        >
                            {expiryLabel()}
                        </span>
                        <span className="text-muted-foreground">
                            Last sync{' '}
                            {connection.last_successful_sync_at
                                ? formatDateTime(
                                      connection.last_successful_sync_at,
                                  )
                                : 'never'}
                        </span>
                    </div>
                    {expiryFraction !== null && (
                        <div className="h-1 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn(
                                    'h-full rounded-full',
                                    expiryBarClass,
                                )}
                                style={{
                                    width: `${expiryFraction * 100}%`,
                                }}
                            />
                        </div>
                    )}
                </div>

                {connection.balance_mismatch && (
                    <Alert variant="destructive" className="py-2">
                        <TriangleAlert className="size-4" />
                        <AlertTitle className="text-xs">
                            Balance mismatch after last sync
                        </AlertTitle>
                        <AlertDescription className="text-xs">
                            Δ{' '}
                            {formatSharedMoney(
                                Number(connection.balance_mismatch.delta),
                                connection.balance_mismatch.currency,
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {(connection.failure_code || connection.failure_message) &&
                    !connection.balance_mismatch && (
                        <p className="text-xs text-destructive">
                            {connection.failure_message ??
                                connection.failure_code}
                        </p>
                    )}

                <div className="flex gap-2">
                    {connection.needs_renewal ? (
                        <Button
                            size="sm"
                            variant="default"
                            onClick={renew}
                            className="flex-1"
                        >
                            <RefreshCw className="size-4" />
                            Renew access
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={syncAll}
                            disabled={connection.linked_accounts_count === 0}
                            className="flex-1"
                        >
                            <RefreshCw className="size-4" />
                            Sync all
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant={pendingCount > 0 ? 'default' : 'outline'}
                        onClick={onManage}
                    >
                        {pendingCount > 0 ? 'Set up' : 'Manage'}
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                size="sm"
                                variant="ghost"
                                aria-label={`More actions for ${connection.aspsp_name}`}
                            >
                                <MoreVertical className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {!connection.needs_renewal && (
                                <DropdownMenuItem onSelect={renew}>
                                    <RefreshCw className="size-4" />
                                    Renew access
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={onRemove}
                            >
                                <Trash2 className="size-4" />
                                Remove bank
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </CardContent>
        </Card>
    );
}

function AccountList({
    label,
    accounts,
    muted = false,
    hideHeader = false,
    onEdit,
    onConnect,
}: {
    label: string;
    accounts: Account[];
    muted?: boolean;
    hideHeader?: boolean;
    onEdit: (account: Account) => void;
    onConnect: (account: Account) => void;
}) {
    const subtotals = muted ? [] : balancesByCurrency(accounts);

    return (
        <section className="flex flex-col gap-2">
            {!hideHeader && (
                <div className="flex items-baseline justify-between gap-2">
                    <div className="flex items-baseline gap-2">
                        <h2 className="text-sm font-semibold">{label}</h2>
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {accounts.length}
                        </span>
                    </div>
                    {subtotals.length > 0 && (
                        <span className="flex flex-wrap items-baseline gap-x-3 text-sm font-medium text-muted-foreground tabular-nums">
                            {subtotals.map((entry) => (
                                <span key={entry.currency}>
                                    {formatMoney(entry.total, entry.currency)}
                                </span>
                            ))}
                        </span>
                    )}
                </div>
            )}
            <Card className="rounded-lg py-0">
                <CardContent className="divide-y p-0">
                    {accounts.map((account) => (
                        <AccountRow
                            key={account.id}
                            account={account}
                            muted={muted}
                            onEdit={() => onEdit(account)}
                            onConnect={() => onConnect(account)}
                        />
                    ))}
                </CardContent>
            </Card>
        </section>
    );
}

function AccountRow({
    account,
    muted,
    onEdit,
    onConnect,
}: {
    account: Account;
    muted: boolean;
    onEdit: () => void;
    onConnect: () => void;
}) {
    const linkedConnection = account.connection_accounts[0] ?? null;
    const needsRenewal = account.connection_accounts.some(
        (connectionAccount) => connectionAccount.needs_renewal,
    );
    const isConnected = account.connection_accounts.length > 0;
    const lastPostingDate =
        account.postings_max_date ?? account.last_known_posting_date;

    const subtitleParts: string[] = [];

    if (account.bank_name !== null) {
        subtitleParts.push(account.bank_name);
    }

    if (linkedConnection !== null) {
        subtitleParts.push(
            linkedConnection.account_number_last_four !== null
                ? `*${linkedConnection.account_number_last_four}`
                : linkedConnection.connection.aspsp_name,
        );
    } else if (account.bank_name === null) {
        subtitleParts.push('Manual account');
    }

    const openAccount = () => router.visit(accountHref(account).url);
    const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openAccount();
        }
    };

    const toggleActive = () => {
        router.patch(
            updateAccount.url(account.id),
            { is_active: !account.is_active },
            { preserveScroll: true },
        );
    };

    const renew = () => {
        if (linkedConnection === null) {
            return;
        }

        router.post(renewConnection.url(linkedConnection.connection.id));
    };

    return (
        <div
            role="link"
            tabIndex={0}
            aria-label={`Open ${account.name}`}
            onClick={openAccount}
            onKeyDown={handleKeyDown}
            className={cn(
                'group flex cursor-pointer items-center gap-4 px-4 py-3 transition-colors first:rounded-t-lg last:rounded-b-lg hover:bg-accent/50 focus-visible:bg-accent/50 focus-visible:outline-none',
                muted && 'opacity-70',
            )}
        >
            <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted">
                {isConnected ? (
                    <Landmark className="size-4 text-muted-foreground" />
                ) : (
                    <WalletCards className="size-4 text-muted-foreground" />
                )}
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="truncate font-medium">{account.name}</span>
                    {account.is_favorite && (
                        <span title="Primary account" className="shrink-0">
                            <Star className="size-3.5 fill-brand text-brand" />
                        </span>
                    )}
                    {needsRenewal && (
                        <Badge variant="destructive">Needs renewal</Badge>
                    )}
                </div>
                <div className="truncate text-sm text-muted-foreground">
                    {subtitleParts.join(' · ')}
                </div>
            </div>

            <div className="hidden w-28 text-right sm:block">
                <div className="text-xs text-muted-foreground">
                    Last posting
                </div>
                <div className="text-sm tabular-nums">
                    {lastPostingDate ? formatDate(lastPostingDate) : '—'}
                </div>
            </div>

            <div className="w-28 text-right md:w-36">
                <div className="text-xs text-muted-foreground">Balance</div>
                <div className="font-semibold tabular-nums">
                    {account.current_balance === null
                        ? '—'
                        : formatMoney(
                              account.current_balance,
                              account.currency,
                          )}
                </div>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        aria-label={`Open actions for ${account.name}`}
                        variant="ghost"
                        size="icon"
                        onClick={stopCardNavigation}
                    >
                        <MoreVertical className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" onClick={stopCardNavigation}>
                    <DropdownMenuItem onSelect={onEdit}>
                        <Pencil className="size-4" />
                        Edit
                    </DropdownMenuItem>
                    {!isConnected && (
                        <DropdownMenuItem onSelect={onConnect}>
                            <Landmark className="size-4" />
                            Connect bank
                        </DropdownMenuItem>
                    )}
                    {needsRenewal && (
                        <DropdownMenuItem onSelect={renew}>
                            <RefreshCw className="size-4" />
                            Renew connection
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem onSelect={toggleActive}>
                        {account.is_active ? (
                            <Archive className="size-4" />
                        ) : (
                            <RotateCcw className="size-4" />
                        )}
                        {account.is_active ? 'Close' : 'Reopen'}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function RemoveConnectionDialog({
    connection,
    onClose,
}: {
    connection: Connection | null;
    onClose: () => void;
}) {
    const remove = () => {
        if (connection === null) {
            return;
        }

        router.delete(destroyConnection.url(connection.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog
            open={connection !== null}
            onOpenChange={(nextOpen) => !nextOpen && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        Remove {connection?.aspsp_name ?? 'bank'}?
                    </DialogTitle>
                    <DialogDescription>
                        This removes the bank connection and its sync history.
                        Your local accounts and postings are kept — they simply
                        stop syncing automatically.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={remove}>
                        <Trash2 className="size-4" />
                        Remove connection
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AddBankDialog({
    open,
    availableBanks,
    defaults,
    onClose,
}: {
    open: boolean;
    availableBanks: AvailableBank[];
    defaults: BankAccountsProps['defaults'];
    onClose: () => void;
}) {
    const defaultBank =
        availableBanks.find(
            (bank) =>
                bank.name === defaults.aspsp_name &&
                bank.country === defaults.aspsp_country,
        ) ?? availableBanks[0];
    const [selectedBankKey, setSelectedBankKey] = useState(
        defaultBank?.key ?? '',
    );
    const selectedBank = availableBanks.find(
        (bank) => bank.key === selectedBankKey,
    );

    const startConnection = () => {
        if (selectedBank === undefined) {
            return;
        }

        router.post(storeConnection.url(), {
            aspsp_name: selectedBank.name,
            aspsp_country: selectedBank.country,
        });
    };

    return (
        <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add bank connection</DialogTitle>
                    <DialogDescription>
                        Choose your bank before continuing to Enable Banking.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3">
                    <Label>Bank</Label>
                    <Select
                        value={selectedBankKey}
                        onValueChange={setSelectedBankKey}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Choose bank" />
                        </SelectTrigger>
                        <SelectContent>
                            {availableBanks.map((bank) => (
                                <SelectItem key={bank.key} value={bank.key}>
                                    {bank.name} ({bank.country})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={startConnection}
                        disabled={selectedBank === undefined}
                    >
                        <Landmark className="size-4" />
                        Continue to Enable Banking
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AccountDialog({
    account,
    open,
    onClose,
}: {
    account: Account | null;
    open: boolean;
    onClose: () => void;
}) {
    const isEditing = account !== null;
    const form = useForm<AccountForm>(
        account === null ? emptyForm : accountToForm(account),
    );
    const typeOptions = useMemo(() => {
        if (
            account?.account_type &&
            !accountTypeOptions.includes(account.account_type)
        ) {
            return [...accountTypeOptions, account.account_type];
        }

        return accountTypeOptions;
    }, [account]);

    const submit = () => {
        form.transform((data) => cleanPayload(data));

        if (isEditing) {
            form.patch(updateAccount.url(account.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        form.post(storeAccount.url(), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEditing ? 'Edit account' : 'Add account'}
                    </DialogTitle>
                    <DialogDescription>
                        Keep imported identifiers and synced balances managed by
                        the bank connection. Edit the account label and how it
                        appears in your overview.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-5">
                    {isEditing && (
                        <div className="grid gap-3 rounded-md border bg-muted/30 p-3 text-sm md:grid-cols-2">
                            <ReadOnlyFact
                                label="Source"
                                value={
                                    account.connection_accounts.length > 0
                                        ? 'Enable Banking'
                                        : 'Manual or imported'
                                }
                            />
                            <ReadOnlyFact
                                label="Current balance"
                                value={formatMoney(
                                    account.current_balance,
                                    account.currency,
                                )}
                            />
                            <ReadOnlyFact
                                label="Postings"
                                value={String(account.postings_count)}
                            />
                            <ReadOnlyFact
                                label="Bank account"
                                value={
                                    account.connection_accounts[0]?.name ??
                                    account.account_subcategory_key ??
                                    'Not connected'
                                }
                            />
                        </div>
                    )}

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="name">Display name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="bank_name">Bank label</Label>
                            <Input
                                id="bank_name"
                                value={form.data.bank_name}
                                onChange={(event) =>
                                    form.setData(
                                        'bank_name',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.bank_name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="currency">Currency</Label>
                            <Input
                                id="currency"
                                maxLength={3}
                                placeholder="EUR"
                                value={form.data.currency}
                                onChange={(event) =>
                                    form.setData(
                                        'currency',
                                        event.target.value.toUpperCase(),
                                    )
                                }
                            />
                            <InputError message={form.errors.currency} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Account type</Label>
                            <Select
                                value={form.data.account_type}
                                onValueChange={(value) =>
                                    form.setData('account_type', value)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {typeOptions.map((option) => (
                                        <SelectItem key={option} value={option}>
                                            {option}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.account_type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="last_known_posting_date">
                                Last known posting date
                            </Label>
                            <Input
                                id="last_known_posting_date"
                                type="date"
                                value={form.data.last_known_posting_date}
                                onChange={(event) =>
                                    form.setData(
                                        'last_known_posting_date',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.last_known_posting_date}
                            />
                        </div>
                    </div>

                    <div className="grid gap-3 rounded-md border p-3">
                        <CheckboxField
                            id="is_favorite"
                            label="Favorite account"
                            description="Show this account more prominently in account lists."
                            checked={form.data.is_favorite}
                            onChange={(checked) =>
                                form.setData('is_favorite', checked)
                            }
                        />
                        <CheckboxField
                            id="is_active"
                            label="Account is open"
                            description="Closed accounts stay searchable and keep their postings."
                            checked={form.data.is_active}
                            onChange={(checked) =>
                                form.setData('is_active', checked)
                            }
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {form.processing ? 'Saving...' : 'Save account'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ConnectBankDialog({
    account,
    linkableConnectionAccounts,
    availableBanks,
    defaults,
    onClose,
}: {
    account: Account | null;
    linkableConnectionAccounts: LinkableConnectionAccount[];
    availableBanks: AvailableBank[];
    defaults: BankAccountsProps['defaults'];
    onClose: () => void;
}) {
    const defaultBank =
        availableBanks.find(
            (bank) =>
                bank.name === defaults.aspsp_name &&
                bank.country === defaults.aspsp_country,
        ) ?? availableBanks[0];
    const [selectedRemoteAccountId, setSelectedRemoteAccountId] = useState('');
    const [selectedBankKey, setSelectedBankKey] = useState(
        defaultBank?.key ?? '',
    );
    const selectedBank = availableBanks.find(
        (bank) => bank.key === selectedBankKey,
    );
    const selectedRemoteAccount = linkableConnectionAccounts.find(
        (connectionAccount) =>
            String(connectionAccount.id) === selectedRemoteAccountId,
    );

    const linkAccount = () => {
        if (account === null || selectedRemoteAccount === undefined) {
            return;
        }

        router.patch(
            updateConnectionAccount.url(selectedRemoteAccount.id),
            {
                mode: 'existing',
                bank_account_id: account.id,
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
            },
        );
    };

    const startConnection = () => {
        if (selectedBank === undefined) {
            return;
        }

        router.post(storeConnection.url(), {
            aspsp_name: selectedBank.name,
            aspsp_country: selectedBank.country,
        });
    };

    return (
        <Dialog
            open={account !== null}
            onOpenChange={(nextOpen) => !nextOpen && onClose()}
        >
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Connect bank</DialogTitle>
                    <DialogDescription>
                        Link {account?.name ?? 'this account'} to an account
                        from an active bank connection, or start a new Enable
                        Banking connection.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-5">
                    {linkableConnectionAccounts.length > 0 && (
                        <>
                            <div className="grid gap-3">
                                <Label>
                                    Pick an account from a connected bank
                                </Label>
                                <div className="grid gap-2">
                                    {linkableConnectionAccounts.map(
                                        (connectionAccount) => (
                                            <button
                                                key={connectionAccount.id}
                                                type="button"
                                                onClick={() =>
                                                    setSelectedRemoteAccountId(
                                                        String(
                                                            connectionAccount.id,
                                                        ),
                                                    )
                                                }
                                                className="flex items-start justify-between gap-3 rounded-md border p-3 text-left text-sm transition-colors hover:bg-accent data-[selected=true]:border-primary data-[selected=true]:bg-accent"
                                                data-selected={
                                                    selectedRemoteAccountId ===
                                                    String(connectionAccount.id)
                                                }
                                            >
                                                <span className="grid gap-1">
                                                    <span className="font-medium">
                                                        {connectionAccount.name}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {
                                                            connectionAccount
                                                                .connection
                                                                .aspsp_name
                                                        }{' '}
                                                        ·{' '}
                                                        {connectionAccount.product ??
                                                            'Account'}{' '}
                                                        ·{' '}
                                                        {
                                                            connectionAccount.currency
                                                        }
                                                        {connectionAccount.account_number_last_four
                                                            ? ` · *${connectionAccount.account_number_last_four}`
                                                            : ''}
                                                    </span>
                                                </span>
                                                {selectedRemoteAccountId ===
                                                    String(
                                                        connectionAccount.id,
                                                    ) && (
                                                    <CheckCircle2 className="size-4 text-primary" />
                                                )}
                                            </button>
                                        ),
                                    )}
                                </div>
                                <Button
                                    onClick={linkAccount}
                                    disabled={
                                        selectedRemoteAccount === undefined
                                    }
                                >
                                    Link selected account
                                </Button>
                            </div>

                            <div className="flex items-center gap-3 text-xs text-muted-foreground uppercase">
                                <div className="h-px flex-1 bg-border" />
                                or
                                <div className="h-px flex-1 bg-border" />
                            </div>
                        </>
                    )}

                    <div className="grid gap-3">
                        <div>
                            <Label>Connect a new bank</Label>
                            <p className="mt-1 text-sm text-muted-foreground">
                                You will be sent to Enable Banking to approve
                                access, and the bank&apos;s accounts show up
                                here afterwards.
                            </p>
                        </div>
                        <Select
                            value={selectedBankKey}
                            onValueChange={setSelectedBankKey}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Choose bank" />
                            </SelectTrigger>
                            <SelectContent>
                                {availableBanks.map((bank) => (
                                    <SelectItem key={bank.key} value={bank.key}>
                                        {bank.name} ({bank.country})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button
                            variant={
                                linkableConnectionAccounts.length > 0
                                    ? 'outline'
                                    : 'default'
                            }
                            onClick={startConnection}
                            disabled={selectedBank === undefined}
                        >
                            <Landmark className="size-4" />
                            Continue to Enable Banking
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function ReadOnlyFact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="font-medium">{value}</div>
        </div>
    );
}

function CheckboxField({
    id,
    label,
    description,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-start gap-3">
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
            />
            <div className="grid gap-1">
                <Label htmlFor={id}>{label}</Label>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}

function connectionStatusDot(status: string): string {
    if (['failed', 'renewal_required', 'revoked'].includes(status)) {
        return 'bg-destructive';
    }

    if (['pending', 'unlinked'].includes(status)) {
        return 'bg-amber-400';
    }

    if (status === 'ignored') {
        return 'bg-muted-foreground/40';
    }

    return 'bg-emerald-500';
}

BankAccounts.layout = {
    breadcrumbs: [
        {
            title: 'Bank accounts',
            href: bankAccountsIndex(),
        },
    ],
};
