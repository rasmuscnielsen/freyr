import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Archive,
    Building2,
    CalendarClock,
    CheckCircle2,
    CreditCard,
    Landmark,
    MoreVertical,
    Pencil,
    Plus,
    RefreshCw,
    RotateCcw,
    Star,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { KeyboardEvent, MouseEvent } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    index as bankAccountsIndex,
    store as storeAccount,
    update as updateAccount,
} from '@/routes/bank-accounts';
import { update as updateConnectionAccount } from '@/routes/bank-connection-accounts';
import {
    renew as renewConnection,
    store as storeConnection,
} from '@/routes/bank-connections';
import { index as postingsIndex } from '@/routes/postings';

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
    last_booked_balance: number | null;
    last_available_balance: number | null;
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
    availableBanks: AvailableBank[];
    defaults: {
        aspsp_name: string | null;
        aspsp_country: string | null;
    };
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
    currency: 'DKK',
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

    return new Intl.NumberFormat('da-DK', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(amount);
}

function postingsHref(account: Account) {
    return postingsIndex({
        query: {
            filter: {
                account: account.id,
            },
        },
    });
}

function stopCardNavigation(event: MouseEvent) {
    event.stopPropagation();
}

export default function BankAccounts({
    accounts,
    linkableConnectionAccounts,
    availableBanks,
    defaults,
}: BankAccountsProps) {
    const [editingAccount, setEditingAccount] = useState<Account | null>(null);
    const [isCreating, setIsCreating] = useState(false);
    const [connectingAccount, setConnectingAccount] = useState<Account | null>(
        null,
    );

    const activeAccounts = accounts.filter((account) => account.is_active);
    const closedAccounts = accounts.filter((account) => !account.is_active);

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
                    <Button onClick={() => setIsCreating(true)}>
                        <Plus className="size-4" />
                        Add account
                    </Button>
                </div>

                {accounts.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No bank accounts yet.
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {[...activeAccounts, ...closedAccounts].map(
                            (account) => (
                                <AccountCard
                                    key={account.id}
                                    account={account}
                                    onEdit={() => setEditingAccount(account)}
                                    onConnect={() =>
                                        setConnectingAccount(account)
                                    }
                                />
                            ),
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
        </>
    );
}

function AccountCard({
    account,
    onEdit,
    onConnect,
}: {
    account: Account;
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

    const openPostings = () => router.visit(postingsHref(account));
    const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openPostings();
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
        <Card
            role="link"
            tabIndex={0}
            onClick={openPostings}
            onKeyDown={handleKeyDown}
            className="group cursor-pointer gap-0 rounded-lg py-0 transition-colors hover:border-primary/40 hover:bg-accent/30 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            <CardContent className="flex h-full flex-col gap-5 p-5">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="truncate text-lg font-semibold">
                                {account.name}
                            </h2>
                            {account.is_favorite && (
                                <Star className="size-4 fill-amber-400 text-amber-400" />
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant={
                                    account.is_active ? 'default' : 'secondary'
                                }
                            >
                                {account.is_active ? 'Active' : 'Closed'}
                            </Badge>
                            {needsRenewal ? (
                                <Badge variant="destructive">
                                    Needs renewal
                                </Badge>
                            ) : isConnected ? (
                                <Badge variant="outline">Connected</Badge>
                            ) : (
                                <Badge variant="secondary">Not connected</Badge>
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
                        <DropdownMenuContent
                            align="end"
                            onClick={stopCardNavigation}
                        >
                            <DropdownMenuItem onSelect={onEdit}>
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
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

                <div className="grid gap-3 text-sm">
                    <div className="flex items-center gap-2 text-muted-foreground">
                        <Building2 className="size-4" />
                        <span className="truncate">
                            {account.bank_name ?? 'No bank label'} ·{' '}
                            {account.account_type ?? 'Account'}
                        </span>
                    </div>
                    <div className="flex items-center gap-2 text-muted-foreground">
                        <CreditCard className="size-4" />
                        <span>
                            {account.currency} · {account.postings_count}{' '}
                            postings
                        </span>
                    </div>
                    <div className="flex items-center gap-2 text-muted-foreground">
                        <CalendarClock className="size-4" />
                        <span>Last posting {lastPostingDate ?? 'never'}</span>
                    </div>
                    {linkedConnection && (
                        <div className="flex items-center gap-2 text-muted-foreground">
                            <Landmark className="size-4" />
                            <span className="truncate">
                                {linkedConnection.connection.aspsp_name}
                                {linkedConnection.account_number_last_four
                                    ? ` · *${linkedConnection.account_number_last_four}`
                                    : ''}
                            </span>
                        </div>
                    )}
                </div>

                <div className="mt-auto flex items-end justify-between gap-3">
                    <div>
                        <div className="text-xs text-muted-foreground">
                            Booked balance
                        </div>
                        <div className="text-xl font-semibold">
                            {formatMoney(
                                account.last_booked_balance,
                                account.currency,
                            )}
                        </div>
                    </div>
                    <div onClick={stopCardNavigation}>
                        {needsRenewal ? (
                            <Button variant="outline" onClick={renew}>
                                <RefreshCw className="size-4" />
                                Renew bank
                            </Button>
                        ) : !isConnected ? (
                            <Button variant="outline" onClick={onConnect}>
                                <Landmark className="size-4" />
                                Connect bank
                            </Button>
                        ) : (
                            <Button asChild variant="ghost">
                                <Link href={postingsHref(account)}>
                                    Postings
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
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
                                    account.last_booked_balance,
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
                    <div className="grid gap-3">
                        <Label>Available accounts</Label>
                        {linkableConnectionAccounts.length > 0 ? (
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
                                                    {connectionAccount.currency}
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
                        ) : (
                            <Alert>
                                <Landmark className="size-4" />
                                <AlertTitle>No unlinked accounts</AlertTitle>
                                <AlertDescription>
                                    Start a new bank connection below. Returned
                                    bank accounts will appear here after Enable
                                    Banking sends you back.
                                </AlertDescription>
                            </Alert>
                        )}
                        <Button
                            onClick={linkAccount}
                            disabled={selectedRemoteAccount === undefined}
                        >
                            Link selected account
                        </Button>
                    </div>

                    <div className="grid gap-3 rounded-md border p-4">
                        <div>
                            <h3 className="font-medium">Add bank connection</h3>
                            <p className="text-sm text-muted-foreground">
                                Choose your bank before continuing to Enable
                                Banking.
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

BankAccounts.layout = {
    breadcrumbs: [
        {
            title: 'Bank accounts',
            href: bankAccountsIndex(),
        },
    ],
};
