import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    RefreshCw,
    Star,
    TriangleAlert,
} from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { index as bankAccountsIndex } from '@/routes/bank-accounts';

type SyncRun = {
    id: number;
    status: string;
    started_at: string | null;
    date_from: string | null;
    date_to: string | null;
    new_postings_count: number;
    updated_postings_count: number;
    duplicate_postings_count: number;
    error_code: string | null;
    error_message: string | null;
    balance_matches: boolean | null;
    balance_delta: string | null;
};

type Connection = {
    id: number;
    status: string;
    aspsp_name: string;
    aspsp_country: string;
    needs_renewal: boolean;
    access_valid_until: string | null;
};

type Account = {
    id: number;
    name: string;
    bank_name: string | null;
    currency: string;
    account_type: string | null;
    last_booked_balance: number | null;
    last_available_balance: number | null;
    last_known_posting_date: string | null;
    last_updated_at: string | null;
    is_active: boolean;
    is_favorite: boolean;
    postings_count: number;
    last_synced_at: string | null;
    connection: Connection | null;
    balance_mismatch: { delta: string } | null;
};

type Posting = {
    id: number;
    date: string | null;
    description: string;
    amount: number;
    balance: number | null;
    currency: string;
    category: { name: string; subcategory: string } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Paginator<T> = {
    data: T[];
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
};

type ShowProps = {
    account: Account;
    postings: Paginator<Posting>;
    syncRuns: SyncRun[];
};

function formatMoney(amount: number | null, currency: string): string {
    if (amount === null) {
        return 'Not synced';
    }

    return new Intl.NumberFormat('da-DK', {
        style: 'currency',
        currency,
        maximumFractionDigits: 2,
    }).format(amount);
}

function formatDateTime(value: string | null): string {
    if (value === null) {
        return 'Never';
    }

    return new Intl.DateTimeFormat('da-DK', {
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

function normalizeLabel(label: string): string {
    return label.replace('&laquo;', 'Previous').replace('&raquo;', 'Next');
}

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (['failed', 'renewal_required', 'revoked'].includes(status)) {
        return 'destructive';
    }

    if (['pending', 'running'].includes(status)) {
        return 'secondary';
    }

    return 'default';
}

export default function BankAccountShow({
    account,
    postings,
    syncRuns,
}: ShowProps) {
    const money = new Intl.NumberFormat('da-DK', {
        style: 'currency',
        currency: account.currency,
        minimumFractionDigits: 2,
    });
    const expiryDays = daysUntil(
        account.connection?.access_valid_until ?? null,
    );

    return (
        <>
            <Head title={account.name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button asChild variant="ghost" size="sm" className="-ml-2">
                        <Link href={bankAccountsIndex()}>
                            <ArrowLeft className="size-4" />
                            Bank accounts
                        </Link>
                    </Button>
                </div>

                <Card className="rounded-lg py-0">
                    <CardContent className="flex flex-col gap-5 p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="space-y-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 className="text-2xl font-semibold tracking-tight">
                                        {account.name}
                                    </h1>
                                    {account.is_favorite && (
                                        <Star className="size-5 fill-amber-400 text-amber-400" />
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge
                                        variant={
                                            account.is_active
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {account.is_active
                                            ? 'Active'
                                            : 'Closed'}
                                    </Badge>
                                    {account.account_type && (
                                        <Badge variant="outline">
                                            {account.account_type}
                                        </Badge>
                                    )}
                                    {account.connection?.needs_renewal && (
                                        <Badge variant="destructive">
                                            Needs renewal
                                        </Badge>
                                    )}
                                </div>
                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Building2 className="size-4" />
                                    <span>
                                        {account.bank_name ?? 'No bank label'}
                                        {account.connection
                                            ? ` · ${account.connection.aspsp_name} (${account.connection.aspsp_country})`
                                            : ''}
                                    </span>
                                </div>
                            </div>

                            <div className="grid gap-4 text-right sm:grid-cols-2">
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Booked balance
                                    </div>
                                    <div className="text-2xl font-semibold">
                                        {formatMoney(
                                            account.last_booked_balance,
                                            account.currency,
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Available balance
                                    </div>
                                    <div className="text-2xl font-semibold">
                                        {formatMoney(
                                            account.last_available_balance,
                                            account.currency,
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="grid gap-3 text-sm text-muted-foreground sm:grid-cols-3">
                            <div className="flex items-center gap-2">
                                <RefreshCw className="size-4" />
                                <span>
                                    Last sync{' '}
                                    {formatDateTime(account.last_synced_at)}
                                </span>
                            </div>
                            <div className="flex items-center gap-2">
                                <CalendarClock className="size-4" />
                                <span>
                                    Last posting{' '}
                                    {account.last_known_posting_date ?? 'never'}
                                </span>
                            </div>
                            <div className="flex items-center gap-2">
                                <span>{account.postings_count} postings</span>
                            </div>
                        </div>

                        {account.connection?.needs_renewal && (
                            <Alert variant="destructive">
                                <TriangleAlert className="size-4" />
                                <AlertTitle>
                                    Bank connection needs renewal
                                </AlertTitle>
                                <AlertDescription>
                                    Renew the connection from the bank accounts
                                    overview to keep syncing this account.
                                </AlertDescription>
                            </Alert>
                        )}

                        {!account.connection?.needs_renewal &&
                            expiryDays !== null &&
                            expiryDays < 14 && (
                                <Alert>
                                    <TriangleAlert className="size-4" />
                                    <AlertTitle>
                                        Access expiring soon
                                    </AlertTitle>
                                    <AlertDescription>
                                        Bank access expires in {expiryDays}{' '}
                                        {expiryDays === 1 ? 'day' : 'days'}.
                                        Renew the connection to avoid
                                        interrupted syncs.
                                    </AlertDescription>
                                </Alert>
                            )}

                        {account.balance_mismatch && (
                            <Alert variant="destructive">
                                <TriangleAlert className="size-4" />
                                <AlertTitle>
                                    Balance mismatch after last sync
                                </AlertTitle>
                                <AlertDescription>
                                    The bank-reported balance differs from our
                                    running total by Δ{' '}
                                    {account.balance_mismatch.delta} kr.
                                </AlertDescription>
                            </Alert>
                        )}
                    </CardContent>
                </Card>

                <div className="overflow-hidden rounded-lg border bg-card">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Date
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Description
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Category
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Amount
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Balance
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {postings.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-3 py-8 text-center text-muted-foreground"
                                        >
                                            No postings for this account yet.
                                        </td>
                                    </tr>
                                ) : (
                                    postings.data.map((posting) => (
                                        <tr
                                            key={posting.id}
                                            className="border-t odd:bg-muted/25"
                                        >
                                            <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                                {posting.date}
                                            </td>
                                            <td className="px-3 py-2 font-medium">
                                                {posting.description}
                                            </td>
                                            <td className="px-3 py-2 text-muted-foreground">
                                                {posting.category
                                                    ? `${posting.category.name} / ${posting.category.subcategory}`
                                                    : 'Uncategorized'}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-3 py-2 text-right font-medium whitespace-nowrap',
                                                    posting.amount < 0
                                                        ? 'text-orange-600 dark:text-orange-400'
                                                        : 'text-emerald-600 dark:text-emerald-400',
                                                )}
                                            >
                                                {money.format(posting.amount)}
                                            </td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap text-muted-foreground">
                                                {posting.balance === null
                                                    ? '—'
                                                    : money.format(
                                                          posting.balance,
                                                      )}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {postings.links.length > 3 && (
                    <div className="flex flex-wrap items-center gap-2">
                        {postings.links.map((link, index) =>
                            link.url === null ? (
                                <Button
                                    key={`${link.label}-${index}`}
                                    variant="outline"
                                    size="sm"
                                    disabled
                                >
                                    {index === 0 ? (
                                        <ChevronLeft className="size-4" />
                                    ) : index === postings.links.length - 1 ? (
                                        <ChevronRight className="size-4" />
                                    ) : (
                                        normalizeLabel(link.label)
                                    )}
                                </Button>
                            ) : (
                                <Button
                                    key={`${link.label}-${index}`}
                                    asChild
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                >
                                    <Link href={link.url} preserveScroll>
                                        {index === 0 ? (
                                            <ChevronLeft className="size-4" />
                                        ) : index ===
                                          postings.links.length - 1 ? (
                                            <ChevronRight className="size-4" />
                                        ) : (
                                            normalizeLabel(link.label)
                                        )}
                                    </Link>
                                </Button>
                            ),
                        )}
                    </div>
                )}

                {syncRuns.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-sm font-medium">Recent syncs</h2>
                        <div className="grid gap-2">
                            {syncRuns.map((run) => (
                                <div
                                    key={run.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border bg-card px-3 py-2 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant={statusVariant(run.status)}
                                        >
                                            {run.status}
                                        </Badge>
                                        <span className="text-muted-foreground">
                                            {formatDateTime(run.started_at)}
                                        </span>
                                        {run.balance_matches === false && (
                                            <Badge variant="destructive">
                                                Balance mismatch Δ{' '}
                                                {run.balance_delta} kr.
                                            </Badge>
                                        )}
                                        {run.balance_matches === true && (
                                            <Badge variant="outline">
                                                Balance reconciled
                                            </Badge>
                                        )}
                                    </div>
                                    <span className="text-muted-foreground">
                                        {run.error_message ??
                                            `+${run.new_postings_count} new · ${run.updated_postings_count} updated · ${run.duplicate_postings_count} duplicate`}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

BankAccountShow.layout = {
    breadcrumbs: [
        {
            title: 'Bank accounts',
            href: bankAccountsIndex(),
        },
    ],
};
