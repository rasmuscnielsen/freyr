import { Head, router, useForm } from '@inertiajs/react';
import {
    Archive,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Landmark,
    Minus,
    MoreVertical,
    Pencil,
    Plus,
    RefreshCw,
    RotateCcw,
    Star,
    Trash2,
    WalletCards,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { KeyboardEvent, MouseEvent } from 'react';
import { toast } from 'sonner';
import { ConnectionSetupDialog } from '@/components/bank-accounts/connection-setup-dialog';
import InputError from '@/components/input-error';
import { currencyLabel } from '@/components/region-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { formatDate, formatRelativeDateTime } from '@/lib/dates';
import { formatMoney as formatSharedMoney } from '@/lib/money';
import { useRegion } from '@/lib/region';
import { cn } from '@/lib/utils';
import {
    destroy as destroyAccount,
    index as bankAccountsIndex,
    show as showAccount,
    store as storeAccount,
    update as updateAccount,
} from '@/routes/bank-accounts';
import { update as updateConnectionAccount } from '@/routes/bank-connection-accounts';
import {
    archive as archiveConnection,
    destroy as destroyConnection,
    renew as renewConnection,
    store as storeConnection,
    sync as syncConnection,
    unarchive as unarchiveConnection,
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
    opening_balance: number | null;
    reporting_balance: number | null;
    last_known_posting_date: string | null;
    is_active: boolean;
    is_favorite: boolean;
    is_spiir_imported: boolean;
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
    bank_account: { id: number; name: string; is_active: boolean } | null;
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
    accounts: ConnectionAccount[];
};

type AvailableBank = {
    key: string;
    name: string;
    country: string;
};

type AccountForm = {
    name: string;
    currency: string;
    opening_balance: string;
};

type BankAccountsProps = {
    accounts: Account[];
    linkableConnectionAccounts: LinkableConnectionAccount[];
    connections: Connection[];
    availableBanks: AvailableBank[];
    currencies: string[];
    defaults: {
        aspsp_name: string | null;
        aspsp_country: string | null;
    };
    setupConnectionId: number | null;
};

const emptyForm: AccountForm = {
    name: '',
    currency: '',
    opening_balance: '0.00',
};

function formatMoney(
    amount: number | null,
    currency: string,
    locale?: string,
): string {
    if (amount === null) {
        return 'Not synced';
    }

    return formatSharedMoney(amount, currency, locale, {
        maximumFractionDigits: 0,
    });
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

function byFavoriteThenName(a: Account, b: Account): number {
    return (
        Number(b.is_favorite) - Number(a.is_favorite) ||
        a.name.localeCompare(b.name)
    );
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

/**
 * Consolidated total in the reporting currency. Foreign balances convert at
 * the newest cached exchange rate, so a total spanning other currencies is
 * approximate and flagged for a "~" prefix. Returns null when the figure
 * cannot be expressed in the reporting currency — none set, or a known
 * balance without a cached rate — leaving the per-currency fallback in place.
 */
function reportingTotal(
    accounts: Account[],
    reportingCurrency: string | null,
): { total: number; currency: string; approximate: boolean } | null {
    if (reportingCurrency === null) {
        return null;
    }

    const valued = accounts.filter(
        (account) => account.current_balance !== null,
    );

    if (
        valued.length === 0 ||
        valued.some((account) => account.reporting_balance === null)
    ) {
        return null;
    }

    return {
        total: valued.reduce(
            (sum, account) => sum + (account.reporting_balance ?? 0),
            0,
        ),
        currency: reportingCurrency,
        approximate: valued.some(
            (account) => account.currency !== reportingCurrency,
        ),
    };
}

export default function BankAccounts({
    accounts,
    linkableConnectionAccounts,
    connections,
    availableBanks,
    currencies,
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
    // The dialog stays mounted across the setup post, so always render the
    // freshest connection object from props rather than stale state.
    const managingConnection =
        connections.find(
            (connection) => connection.id === managingConnectionId,
        ) ?? null;
    // Any unlinked account means the connection still needs its initial setup.
    const isSetupMode =
        managingConnection?.accounts.some(
            (account) => account.status === 'unlinked',
        ) ?? false;

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
        }
    };
    const [removingConnection, setRemovingConnection] =
        useState<Connection | null>(null);
    const [deletingAccount, setDeletingAccount] = useState<Account | null>(
        null,
    );
    const [addingConnection, setAddingConnection] = useState(false);

    const accountsById = new Map(
        accounts.map((account) => [account.id, account]),
    );

    // Local accounts linked to a connection render inside that bank's card —
    // including archived ones, which the card collapses under an expandable row.
    const linkedAccountsFor = (connection: Connection): Account[] => {
        const linked: Account[] = [];
        const seen = new Set<number>();

        for (const remoteAccount of connection.accounts) {
            const localId = remoteAccount.bank_account?.id;

            if (localId === undefined || seen.has(localId)) {
                continue;
            }

            seen.add(localId);

            const account = accountsById.get(localId);

            if (account !== undefined) {
                linked.push(account);
            }
        }

        return linked.sort(byFavoriteThenName);
    };

    const manualAccounts = accounts
        .filter(
            (account) =>
                account.is_active && account.connection_accounts.length === 0,
        )
        .sort(byFavoriteThenName);
    const archivedAccounts = accounts.filter(
        (account) =>
            !account.is_active && account.connection_accounts.length === 0,
    );
    const hasNothing = accounts.length === 0 && connections.length === 0;

    // Only Spiir-imported accounts may be linked to a live bank feed.
    const linkableAccounts = manualAccounts.filter(
        (account) => account.is_spiir_imported,
    );

    return (
        <>
            <Head title="Bank accounts" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Bank accounts
                    </h1>
                    <div className="flex flex-wrap gap-2">
                        <div className="flex">
                            <Button
                                className="rounded-r-none"
                                onClick={() => setAddingConnection(true)}
                            >
                                <Landmark className="size-4" />
                                Add bank
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        className="rounded-l-none border-l-0 px-2"
                                        aria-label="More ways to add an account"
                                    >
                                        <ChevronDown className="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onSelect={() => setIsCreating(true)}
                                    >
                                        <Plus className="size-4" />
                                        Add manual account
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                </div>

                {hasNothing ? (
                    <EmptyState
                        onAddBank={() => setAddingConnection(true)}
                        onAddAccount={() => setIsCreating(true)}
                    />
                ) : (
                    <div className="flex flex-col gap-6">
                        {connections.map((connection) => (
                            <BankCard
                                key={connection.id}
                                connection={connection}
                                accounts={linkedAccountsFor(connection)}
                                onManage={() =>
                                    setManagingConnectionId(connection.id)
                                }
                                onRemove={() =>
                                    setRemovingConnection(connection)
                                }
                                onEdit={setEditingAccount}
                                onConnect={setConnectingAccount}
                                onDelete={setDeletingAccount}
                            />
                        ))}

                        {manualAccounts.length > 0 && (
                            <ManualAccountsCard
                                accounts={manualAccounts}
                                onAdd={() => setIsCreating(true)}
                                onEdit={setEditingAccount}
                                onConnect={setConnectingAccount}
                                onDelete={setDeletingAccount}
                            />
                        )}

                        {archivedAccounts.length > 0 && (
                            <Collapsible defaultOpen={false} className="group">
                                <CollapsibleTrigger className="flex w-full items-center gap-2 rounded-md py-1 text-left">
                                    <ChevronRight className="size-4 text-muted-foreground transition-transform group-data-[state=open]:rotate-90" />
                                    <span className="text-sm font-semibold">
                                        Archived
                                    </span>
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {archivedAccounts.length}
                                    </span>
                                </CollapsibleTrigger>
                                <CollapsibleContent className="pt-2">
                                    <Card className="rounded-lg py-0">
                                        <CardContent className="divide-y p-0">
                                            {archivedAccounts.map((account) => (
                                                <AccountRow
                                                    key={account.id}
                                                    account={account}
                                                    muted
                                                    onEdit={() =>
                                                        setEditingAccount(
                                                            account,
                                                        )
                                                    }
                                                    onConnect={() =>
                                                        setConnectingAccount(
                                                            account,
                                                        )
                                                    }
                                                    onDelete={() =>
                                                        setDeletingAccount(
                                                            account,
                                                        )
                                                    }
                                                />
                                            ))}
                                        </CardContent>
                                    </Card>
                                </CollapsibleContent>
                            </Collapsible>
                        )}
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
                currencies={currencies}
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

            <DeleteAccountDialog
                account={deletingAccount}
                onClose={() => setDeletingAccount(null)}
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

function TotalSummary({ accounts }: { accounts: Account[] }) {
    const { locale, reportingCurrency } = useRegion();
    const total = reportingTotal(accounts, reportingCurrency);

    if (total !== null) {
        return (
            <div className="hidden text-right sm:block">
                <div className="text-xs text-muted-foreground">Total</div>
                <div
                    className="font-semibold tabular-nums"
                    title={
                        total.approximate
                            ? 'Approximate — converted at the latest exchange rate'
                            : undefined
                    }
                >
                    {total.approximate && '≈'}
                    {formatMoney(total.total, total.currency, locale)}
                </div>
            </div>
        );
    }

    const subtotals = balancesByCurrency(accounts);

    if (subtotals.length === 0) {
        return null;
    }

    return (
        <div className="hidden text-right sm:block">
            <div className="text-xs text-muted-foreground">Total</div>
            {subtotals.map((entry) => (
                <div
                    key={entry.currency}
                    className="font-semibold tabular-nums"
                >
                    {formatMoney(entry.total, entry.currency, locale)}
                </div>
            ))}
        </div>
    );
}

function BankCard({
    connection,
    accounts,
    onManage,
    onRemove,
    onEdit,
    onConnect,
    onDelete,
}: {
    connection: Connection;
    accounts: Account[];
    onManage: () => void;
    onRemove: () => void;
    onEdit: (account: Account) => void;
    onConnect: (account: Account) => void;
    onDelete: (account: Account) => void;
}) {
    const { locale, timezone } = useRegion();
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

    const [archivedOpen, setArchivedOpen] = useState(false);

    const isArchived = connection.status === 'archived';

    // Remote accounts without a local counterpart — whether never decided or
    // deliberately ignored — are offered under one "available" row.
    const availableCount = connection.accounts.filter(
        (account) => account.bank_account === null,
    ).length;

    const openAccounts = accounts.filter((account) => account.is_active);
    const archivedAccounts = accounts.filter((account) => !account.is_active);

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

    const toggleArchived = () => {
        router.post(
            (isArchived ? unarchiveConnection : archiveConnection).url(
                connection.id,
            ),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <Card className="overflow-hidden py-0">
            <CardContent className="p-0">
                <div className="flex flex-col gap-3 p-4">
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand/10">
                                <Landmark className="size-5 text-brand" />
                            </div>
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <h2 className="truncate font-semibold">
                                        {connection.aspsp_name}
                                    </h2>
                                    {isArchived && (
                                        <Badge variant="secondary">
                                            Archived
                                        </Badge>
                                    )}
                                </div>
                                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <span
                                        className={cn(
                                            'size-1.5 shrink-0 rounded-full',
                                            connectionStatusDot(
                                                connection.status,
                                            ),
                                        )}
                                        aria-hidden
                                    />
                                    <span className="truncate capitalize">
                                        {connection.aspsp_country} ·{' '}
                                        {connection.status.replace('_', ' ')} ·{' '}
                                        {openAccounts.length}{' '}
                                        {openAccounts.length === 1
                                            ? 'account'
                                            : 'accounts'}
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-3">
                            <TotalSummary accounts={openAccounts} />
                            {connection.needs_renewal && (
                                <Button size="sm" onClick={renew}>
                                    <RefreshCw className="size-4" />
                                    Renew access
                                </Button>
                            )}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Actions for ${connection.aspsp_name}`}
                                    >
                                        <MoreVertical className="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onSelect={syncAll}
                                        disabled={
                                            isArchived ||
                                            connection.linked_accounts_count ===
                                                0
                                        }
                                    >
                                        <RefreshCw className="size-4" />
                                        Sync all
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onSelect={onManage}>
                                        <WalletCards className="size-4" />
                                        Manage accounts
                                    </DropdownMenuItem>
                                    {!connection.needs_renewal && (
                                        <DropdownMenuItem onSelect={renew}>
                                            <RotateCcw className="size-4" />
                                            Renew access
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onSelect={toggleArchived}>
                                        {isArchived ? (
                                            <RotateCcw className="size-4" />
                                        ) : (
                                            <Archive className="size-4" />
                                        )}
                                        {isArchived
                                            ? 'Unarchive bank'
                                            : 'Archive bank'}
                                    </DropdownMenuItem>
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
                                Last sync:{' '}
                                {formatRelativeDateTime(
                                    connection.last_successful_sync_at,
                                    timezone,
                                    locale,
                                )}
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
                </div>

                {(connection.failure_code || connection.failure_message) && (
                    <p className="mx-4 mb-3 text-xs text-destructive">
                        {connection.failure_message ?? connection.failure_code}
                    </p>
                )}

                {openAccounts.length > 0 ? (
                    <div className="divide-y border-t">
                        {openAccounts.map((account) => (
                            <AccountRow
                                key={account.id}
                                account={account}
                                muted={false}
                                onEdit={() => onEdit(account)}
                                onConnect={() => onConnect(account)}
                                onDelete={() => onDelete(account)}
                            />
                        ))}
                    </div>
                ) : (
                    archivedAccounts.length === 0 && (
                        <div className="border-t px-4 py-6 text-center text-sm text-muted-foreground">
                            {connection.accounts.length === 0
                                ? 'No accounts returned by this bank yet.'
                                : 'No accounts added from this bank yet.'}
                        </div>
                    )
                )}

                {availableCount > 0 && (
                    <button
                        type="button"
                        onClick={onManage}
                        className="flex w-full items-center gap-3 border-t px-4 py-3 text-left text-sm text-muted-foreground transition-colors hover:bg-accent/50"
                    >
                        <Plus className="size-4 shrink-0" />
                        <span>
                            {availableCount} &nbsp;available{' '}
                            {availableCount === 1 ? 'account' : 'accounts'}
                        </span>
                        <ChevronRight className="ml-auto size-4" />
                    </button>
                )}

                {archivedAccounts.length > 0 && (
                    <Collapsible
                        open={archivedOpen}
                        onOpenChange={setArchivedOpen}
                    >
                        <CollapsibleTrigger className="flex w-full items-center gap-3 border-t px-4 py-3 text-left text-sm text-muted-foreground transition-colors hover:bg-accent/50">
                            {archivedOpen ? (
                                <Minus className="size-4 shrink-0" />
                            ) : (
                                <Plus className="size-4 shrink-0" />
                            )}
                            <span>
                                {archivedAccounts.length} &nbsp;archived{' '}
                                {archivedAccounts.length === 1
                                    ? 'account'
                                    : 'accounts'}
                            </span>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="divide-y border-t">
                            {archivedAccounts.map((account) => (
                                <AccountRow
                                    key={account.id}
                                    account={account}
                                    muted
                                    onEdit={() => onEdit(account)}
                                    onConnect={() => onConnect(account)}
                                    onDelete={() => onDelete(account)}
                                />
                            ))}
                        </CollapsibleContent>
                    </Collapsible>
                )}
            </CardContent>
        </Card>
    );
}

function ManualAccountsCard({
    accounts,
    onAdd,
    onEdit,
    onConnect,
    onDelete,
}: {
    accounts: Account[];
    onAdd: () => void;
    onEdit: (account: Account) => void;
    onConnect: (account: Account) => void;
    onDelete: (account: Account) => void;
}) {
    return (
        <Card className="overflow-hidden py-0">
            <CardContent className="p-0">
                <div className="flex items-center justify-between gap-3 p-4">
                    <div className="flex min-w-0 items-center gap-3">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <WalletCards className="size-5 text-muted-foreground" />
                        </div>
                        <div className="min-w-0">
                            <h2 className="font-semibold">Manual accounts</h2>
                            <p className="text-xs text-muted-foreground">
                                {accounts.length}{' '}
                                {accounts.length === 1 ? 'account' : 'accounts'}{' '}
                                · tracked by hand
                            </p>
                        </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-3">
                        <TotalSummary accounts={accounts} />
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Manual accounts actions"
                                >
                                    <MoreVertical className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem onSelect={onAdd}>
                                    <Plus className="size-4" />
                                    Add account
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
                <div className="divide-y border-t">
                    {accounts.map((account) => (
                        <AccountRow
                            key={account.id}
                            account={account}
                            muted={false}
                            onEdit={() => onEdit(account)}
                            onConnect={() => onConnect(account)}
                            onDelete={() => onDelete(account)}
                        />
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

function AccountRow({
    account,
    muted,
    onEdit,
    onConnect,
    onDelete,
}: {
    account: Account;
    muted: boolean;
    onEdit: () => void;
    onConnect: () => void;
    onDelete: () => void;
}) {
    const { locale } = useRegion();
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

    const toggleFavorite = () => {
        router.patch(
            updateAccount.url(account.id),
            { is_favorite: !account.is_favorite },
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
                    {lastPostingDate
                        ? formatDate(lastPostingDate, locale)
                        : '—'}
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
                              locale,
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
                        Edit account
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={toggleFavorite}>
                        <Star
                            className={cn(
                                'size-4',
                                account.is_favorite && 'fill-current',
                            )}
                        />
                        {account.is_favorite
                            ? 'Remove from favorites'
                            : 'Mark as favorite'}
                    </DropdownMenuItem>
                    {!isConnected && account.is_spiir_imported && (
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
                        {account.is_active ? 'Archive' : 'Unarchive'}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" onSelect={onDelete}>
                        <Trash2 className="size-4" />
                        Delete account
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function DeleteAccountDialog({
    account,
    onClose,
}: {
    account: Account | null;
    onClose: () => void;
}) {
    const remove = () => {
        if (account === null) {
            return;
        }

        router.delete(destroyAccount.url(account.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const isConnected = (account?.connection_accounts.length ?? 0) > 0;

    return (
        <Dialog
            open={account !== null}
            onOpenChange={(nextOpen) => !nextOpen && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        Delete {account?.name ?? 'account'}?
                    </DialogTitle>
                    <DialogDescription>
                        This permanently deletes the account and all of its
                        postings.
                        {isConnected &&
                            ' The remote account returns to the connection\u2019s setup list and can be linked again.'}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={remove}>
                        <Trash2 className="size-4" />
                        Delete account
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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
                        This permanently removes the bank connection, its remote
                        accounts, and its sync history. Local accounts and
                        postings are kept but can never be re-linked — to pause
                        syncing without losing the connection, archive it
                        instead.
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
    const [starting, setStarting] = useState(false);
    const selectedBank = availableBanks.find(
        (bank) => bank.key === selectedBankKey,
    );

    const startConnection = () => {
        if (selectedBank === undefined || starting) {
            return;
        }

        setStarting(true);
        router.post(
            storeConnection.url(),
            {
                aspsp_name: selectedBank.name,
                aspsp_country: selectedBank.country,
            },
            { onFinish: () => setStarting(false) },
        );
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
                        disabled={selectedBank === undefined || starting}
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
    currencies,
    open,
    onClose,
}: {
    account: Account | null;
    currencies: string[];
    open: boolean;
    onClose: () => void;
}) {
    const isEditing = account !== null;
    const currencyOptions = useMemo(
        () =>
            [...currencies].sort((a, b) =>
                currencyLabel(a).localeCompare(currencyLabel(b)),
            ),
        [currencies],
    );
    const form = useForm<AccountForm>(
        account === null
            ? emptyForm
            : {
                  name: account.name,
                  currency: account.currency,
                  opening_balance: account.opening_balance?.toFixed(2) ?? '',
              },
    );

    const submit = () => {
        if (isEditing) {
            form.transform(() => ({
                name: form.data.name,
                ...(account.connection_accounts.length === 0 &&
                form.data.opening_balance.trim() !== ''
                    ? { opening_balance: form.data.opening_balance }
                    : {}),
            }));
            form.patch(updateAccount.url(account.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        form.transform(() => ({
            ...form.data,
            opening_balance: form.data.opening_balance.trim() || '0.00',
        }));
        form.post(storeAccount.url(), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {isEditing ? 'Edit account' : 'Add manual account'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEditing
                            ? 'Update account details and, for an unconnected account, its opening balance.'
                            : 'A manual account never syncs with a bank — postings and balances are entered by hand.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2">
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

                    {!isEditing && (
                        <div className="grid gap-2">
                            <Label htmlFor="currency">Currency</Label>
                            <Select
                                value={form.data.currency}
                                onValueChange={(value) =>
                                    form.setData('currency', value)
                                }
                                name="currency"
                            >
                                <SelectTrigger id="currency" className="w-full">
                                    <SelectValue placeholder="Select currency" />
                                </SelectTrigger>
                                <SelectContent>
                                    {currencyOptions.map((currency) => (
                                        <SelectItem
                                            key={currency}
                                            value={currency}
                                        >
                                            {currencyLabel(currency)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.currency} />
                        </div>
                    )}

                    {(account === null ||
                        account.connection_accounts.length === 0) && (
                        <div className="grid gap-2">
                            <Label htmlFor="opening_balance">
                                Opening balance
                                {isEditing ? ` (${account.currency})` : ''}
                            </Label>
                            <Input
                                id="opening_balance"
                                type="number"
                                step="0.01"
                                value={form.data.opening_balance}
                                onChange={(event) =>
                                    form.setData(
                                        'opening_balance',
                                        event.target.value,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {isEditing
                                    ? account.opening_balance === null
                                        ? 'Balance before all postings. Leave blank to keep the imported balance.'
                                        : 'Balance before all postings. Leave blank to keep the existing opening balance.'
                                    : 'Balance before all postings. A blank value starts at zero.'}
                            </p>
                            <InputError message={form.errors.opening_balance} />
                        </div>
                    )}
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
    const [starting, setStarting] = useState(false);
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
        if (selectedBank === undefined || starting) {
            return;
        }

        setStarting(true);
        router.post(
            storeConnection.url(),
            {
                aspsp_name: selectedBank.name,
                aspsp_country: selectedBank.country,
            },
            { onFinish: () => setStarting(false) },
        );
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
                            disabled={selectedBank === undefined || starting}
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

function connectionStatusDot(status: string): string {
    if (['failed', 'renewal_required', 'revoked'].includes(status)) {
        return 'bg-destructive';
    }

    if (['pending', 'unlinked'].includes(status)) {
        return 'bg-amber-400';
    }

    if (['ignored', 'archived'].includes(status)) {
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
