import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Plus,
    Sparkles,
    Star,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { CategoryComboboxOption } from '@/components/postings/category-combobox';
import { PostingFormDialog } from '@/components/postings/posting-form-dialog';
import type { PostingFormValues } from '@/components/postings/posting-form-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatRelativeDateTime } from '@/lib/dates';
import { formatMoney as formatSharedMoney } from '@/lib/money';
import { useRegion } from '@/lib/region';
import { cn } from '@/lib/utils';
import { index as bankAccountsIndex } from '@/routes/bank-accounts';
import {
    destroy as destroyPosting,
    store as storePosting,
    update as updatePosting,
} from '@/routes/bank-accounts/postings';
import { update as updateAnyPosting } from '@/routes/postings';

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
    balance_match_basis: string | null;
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
    current_balance: number | null;
    last_known_posting_date: string | null;
    last_updated_at: string | null;
    is_active: boolean;
    is_favorite: boolean;
    is_manual: boolean;
    postings_count: number;
    last_synced_at: string | null;
    connection: Connection | null;
    balance_diff: string | null;
};

type AuditPosting = {
    id: number;
    date: string;
    description: string;
    amount: number | null;
    balance: number | null;
};

type BalanceBreak = {
    kind:
        | 'gap'
        | 'possible_duplicate'
        | 'suspect_day'
        | 'tail_lag'
        | 'incomplete';
    date: string | null;
    delta: string | null;
    postings: AuditPosting[];
};

type BalanceAudit = {
    is_consistent: boolean;
    total_delta: string | null;
    checked_postings: number;
    breaks: BalanceBreak[];
};

type Posting = {
    id: number;
    date: string | null;
    description: string;
    original_amount: number | null;
    original_currency: string;
    balance: number | null;
    comment: string | null;
    is_extraordinary: boolean;
    categorized_subcategory_id: number | null;
    category: { name: string; subcategory: string } | null;
};

type Category = {
    id: number;
    name: string;
    type: string;
    subcategories: { id: number; name: string }[];
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
    categories: Category[];
    balanceAudit?: BalanceAudit | null;
};

function emptyPostingForm(): PostingFormValues {
    const today = new Date();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');

    return {
        date: `${today.getFullYear()}-${month}-${day}`,
        description: '',
        amount: '',
        categorized_subcategory_id: null,
        comment: '',
        is_extraordinary: false,
    };
}

function postingToForm(posting: Posting): PostingFormValues {
    return {
        date: posting.date ?? '',
        description: posting.description,
        amount: posting.original_amount?.toFixed(2) ?? '',
        categorized_subcategory_id: posting.categorized_subcategory_id,
        comment: posting.comment ?? '',
        is_extraordinary: posting.is_extraordinary,
    };
}

function formatMoney(
    amount: number | null,
    currency: string,
    locale?: string,
): string {
    if (amount === null) {
        return '—';
    }

    return formatSharedMoney(amount, currency, locale, {
        maximumFractionDigits: 2,
    });
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
): 'success' | 'secondary' | 'destructive' | 'outline' {
    if (['failed', 'renewal_required', 'revoked'].includes(status)) {
        return 'destructive';
    }

    if (['pending', 'running'].includes(status)) {
        return 'secondary';
    }

    return 'success';
}

export default function BankAccountShow({
    account,
    postings,
    syncRuns,
    categories,
    balanceAudit,
}: ShowProps) {
    const [isCreatingPosting, setIsCreatingPosting] = useState(false);
    const [editingPosting, setEditingPosting] = useState<Posting | null>(null);
    const [deletingPosting, setDeletingPosting] = useState<Posting | null>(
        null,
    );
    const [isAuditOpen, setIsAuditOpen] = useState(false);
    const categoryOptions = useMemo<CategoryComboboxOption[]>(
        () =>
            categories.flatMap((category) =>
                category.subcategories.map((subcategory) => ({
                    id: subcategory.id,
                    label: `${category.name} / ${subcategory.name}`,
                })),
            ),
        [categories],
    );
    const { locale, timezone } = useRegion();
    const money = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: account.currency,
        minimumFractionDigits: 2,
    });
    const expiryDays = daysUntil(
        account.connection?.access_valid_until ?? null,
    );

    const subtitleParts: string[] = [];

    const bankLabel = account.bank_name || account.connection?.aspsp_name;

    if (bankLabel) {
        subtitleParts.push(bankLabel);
    }

    if (account.account_type) {
        subtitleParts.push(account.account_type);
    }

    subtitleParts.push(`${account.postings_count} postings`);

    if (!account.is_manual) {
        subtitleParts.push(
            `Synced ${formatRelativeDateTime(account.last_synced_at, timezone, locale)}`,
        );
    }

    return (
        <>
            <Head title={account.name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="truncate text-2xl font-semibold tracking-tight">
                                {account.name}
                            </h1>
                            {account.is_favorite && (
                                <span title="Primary account">
                                    <Star className="size-4.5 fill-brand text-brand" />
                                </span>
                            )}
                            {!account.is_active && (
                                <Badge variant="secondary">Archived</Badge>
                            )}
                            {account.connection?.needs_renewal && (
                                <Badge variant="destructive">
                                    Needs renewal
                                </Badge>
                            )}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {subtitleParts.join(' · ')}
                        </p>
                    </div>
                    <div className="text-right">
                        <div className="text-2xl font-semibold tabular-nums">
                            {formatMoney(
                                account.current_balance,
                                account.currency,
                                locale,
                            )}
                        </div>
                        {account.is_manual && (
                            <div className="text-xs text-muted-foreground">
                                Manual account
                            </div>
                        )}
                        {account.balance_diff !== null && (
                            <Badge variant="destructive" className="mt-1">
                                Off by{' '}
                                {formatSharedMoney(
                                    Number(account.balance_diff),
                                    account.currency,
                                    locale,
                                )}
                            </Badge>
                        )}
                    </div>
                </div>

                {account.connection?.needs_renewal && (
                    <Alert variant="destructive">
                        <TriangleAlert className="size-4" />
                        <AlertTitle>Bank connection needs renewal</AlertTitle>
                        <AlertDescription>
                            Renew the connection from the bank accounts overview
                            to keep syncing this account.
                        </AlertDescription>
                    </Alert>
                )}

                {!account.connection?.needs_renewal &&
                    expiryDays !== null &&
                    expiryDays < 14 && (
                        <Alert>
                            <TriangleAlert className="size-4" />
                            <AlertTitle>Access expiring soon</AlertTitle>
                            <AlertDescription>
                                Bank access expires in {expiryDays}{' '}
                                {expiryDays === 1 ? 'day' : 'days'}. Renew the
                                connection to avoid interrupted syncs.
                            </AlertDescription>
                        </Alert>
                    )}

                {account.balance_diff !== null && (
                    <Alert variant="destructive">
                        <TriangleAlert className="size-4" />
                        <AlertTitle>
                            Postings don't add up to the bank balance
                        </AlertTitle>
                        <AlertDescription className="flex flex-wrap items-center gap-3">
                            <span>
                                The bank-reported balance differs from the
                                running total of your postings by{' '}
                                {formatSharedMoney(
                                    Number(account.balance_diff),
                                    account.currency,
                                    locale,
                                )}
                                .
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setIsAuditOpen(true)}
                            >
                                Show details
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex items-center justify-between">
                    <h2 className="text-sm font-medium">Postings</h2>
                    {account.is_manual && (
                        <Button
                            size="sm"
                            onClick={() => setIsCreatingPosting(true)}
                        >
                            <Plus className="size-4" />
                            Add posting
                        </Button>
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border bg-card shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm md:min-w-[720px]">
                            <thead className="text-left">
                                <tr>
                                    <th className="px-3 py-2.5 text-xs font-medium text-muted-foreground">
                                        Date
                                    </th>
                                    <th className="px-3 py-2.5 text-xs font-medium text-muted-foreground">
                                        Description
                                    </th>
                                    <th className="hidden px-3 py-2.5 text-xs font-medium text-muted-foreground md:table-cell">
                                        Category
                                    </th>
                                    <th className="px-3 py-2.5 text-right text-xs font-medium text-muted-foreground">
                                        Amount
                                    </th>
                                    <th className="hidden px-3 py-2.5 text-right text-xs font-medium text-muted-foreground md:table-cell">
                                        Balance
                                    </th>
                                    {account.is_manual && (
                                        <th className="px-3 py-2.5 text-right text-xs font-medium text-muted-foreground">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {postings.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={account.is_manual ? 6 : 5}
                                            className="px-3 py-8 text-center text-muted-foreground"
                                        >
                                            No postings for this account yet.
                                        </td>
                                    </tr>
                                ) : (
                                    postings.data.map((posting) => (
                                        <tr
                                            key={posting.id}
                                            className="cursor-pointer border-t transition-colors hover:bg-muted/40"
                                            onClick={() =>
                                                setEditingPosting(posting)
                                            }
                                        >
                                            <td className="px-3 py-3 whitespace-nowrap text-muted-foreground tabular-nums">
                                                {formatDate(
                                                    posting.date,
                                                    locale,
                                                )}
                                            </td>
                                            <td className="px-3 py-3 font-medium">
                                                <span className="flex items-center gap-1.5">
                                                    {posting.description}
                                                    {posting.is_extraordinary && (
                                                        <span title="Extraordinary">
                                                            <Sparkles className="size-3.5 shrink-0 text-brand" />
                                                        </span>
                                                    )}
                                                </span>
                                                {/* The category column is hidden
                                                    on small screens so the amount
                                                    stays in view. */}
                                                <span className="block text-xs font-normal text-muted-foreground md:hidden">
                                                    {posting.category
                                                        ? `${posting.category.name} / ${posting.category.subcategory}`
                                                        : 'Uncategorized'}
                                                </span>
                                            </td>
                                            <td className="hidden px-3 py-3 text-muted-foreground md:table-cell">
                                                {posting.category
                                                    ? `${posting.category.name} / ${posting.category.subcategory}`
                                                    : 'Uncategorized'}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-3 py-3 text-right font-medium whitespace-nowrap tabular-nums',
                                                    posting.original_amount !==
                                                        null &&
                                                        posting.original_amount >=
                                                            0 &&
                                                        'text-chart-income',
                                                )}
                                            >
                                                {posting.original_amount ===
                                                null
                                                    ? '—'
                                                    : money.format(
                                                          posting.original_amount,
                                                      )}
                                            </td>
                                            <td className="hidden px-3 py-3 text-right whitespace-nowrap text-muted-foreground tabular-nums md:table-cell">
                                                {posting.balance === null
                                                    ? '—'
                                                    : money.format(
                                                          posting.balance,
                                                      )}
                                            </td>
                                            {account.is_manual && (
                                                <td className="px-3 py-3 text-right whitespace-nowrap">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-7 text-muted-foreground hover:text-destructive"
                                                        aria-label="Delete posting"
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            setDeletingPosting(
                                                                posting,
                                                            );
                                                        }}
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </Button>
                                                </td>
                                            )}
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
                                            {formatRelativeDateTime(
                                                run.started_at,
                                                timezone,
                                                locale,
                                            )}
                                        </span>
                                        {run.balance_matches === false && (
                                            <Badge variant="destructive">
                                                Balance mismatch Δ{' '}
                                                {formatSharedMoney(
                                                    Number(run.balance_delta),
                                                    account.currency,
                                                    locale,
                                                )}
                                            </Badge>
                                        )}
                                        {run.balance_matches === true && (
                                            <Badge variant="outline">
                                                {run.balance_match_basis ===
                                                'derived'
                                                    ? 'Balance derived from bank snapshot'
                                                    : 'Balance reconciled'}
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

            {!account.is_manual && (
                <BalanceAuditDialog
                    account={account}
                    audit={balanceAudit}
                    open={isAuditOpen}
                    onClose={() => setIsAuditOpen(false)}
                />
            )}

            <PostingFormDialog
                key={
                    editingPosting === null
                        ? `posting-dialog-${isCreatingPosting ? 'create' : 'closed'}`
                        : `posting-dialog-${editingPosting.id}`
                }
                open={editingPosting !== null || isCreatingPosting}
                onClose={() => {
                    setEditingPosting(null);
                    setIsCreatingPosting(false);
                }}
                title={editingPosting === null ? 'Add posting' : 'Edit posting'}
                description={
                    editingPosting === null
                        ? `Record a posting on ${account.name}. Use a negative amount for money going out.`
                        : account.is_manual
                          ? 'Adjust the details of this posting.'
                          : 'Postings from your bank are read-only, but you can categorize them, comment, and mark them extraordinary.'
                }
                currency={account.currency}
                categoryOptions={categoryOptions}
                initial={
                    editingPosting === null
                        ? emptyPostingForm()
                        : postingToForm(editingPosting)
                }
                coreFieldsEditable={account.is_manual}
                action={
                    editingPosting === null
                        ? { method: 'post', url: storePosting.url(account.id) }
                        : account.is_manual
                          ? {
                                method: 'patch',
                                url: updatePosting.url({
                                    bank_account: account.id,
                                    posting: editingPosting.id,
                                }),
                            }
                          : {
                                method: 'patch',
                                url: updateAnyPosting.url(editingPosting.id),
                            }
                }
            />

            {account.is_manual && (
                <DeletePostingDialog
                    account={account}
                    posting={deletingPosting}
                    onClose={() => setDeletingPosting(null)}
                />
            )}
        </>
    );
}

const breakKindLabels: Record<
    BalanceBreak['kind'],
    { title: string; explanation: string }
> = {
    gap: {
        title: 'Missing transaction',
        explanation:
            'The running balance jumps here — a transaction of about this amount appears to be missing (or extra) before this day.',
    },
    possible_duplicate: {
        title: 'Possible duplicate',
        explanation:
            'These identical postings would explain the difference if one of them was imported twice.',
    },
    suspect_day: {
        title: 'Inconsistent day',
        explanation:
            "This day's postings contradict their own running balances — one of them is likely wrong.",
    },
    tail_lag: {
        title: 'Bank balance is ahead',
        explanation:
            'The bank-reported balance is ahead of the newest posting. This is often just pending or not-yet-synced transactions.',
    },
    incomplete: {
        title: 'Incomplete account history',
        explanation:
            'This posting has no verified account-currency amount, so balances cannot be calculated through it.',
    },
};

function BalanceAuditDialog({
    account,
    audit,
    open,
    onClose,
}: {
    account: Account;
    audit: BalanceAudit | null | undefined;
    open: boolean;
    onClose: () => void;
}) {
    const { locale } = useRegion();

    useEffect(() => {
        if (open && audit === undefined) {
            router.reload({ only: ['balanceAudit'] });
        }
    }, [open, audit]);

    return (
        <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Balance check</DialogTitle>
                    <DialogDescription>
                        Compares the running balance of your postings against
                        the bank-reported balance and points at where the chain
                        stops adding up.
                    </DialogDescription>
                </DialogHeader>

                {audit == null ? (
                    <div className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
                        <Spinner />
                        Checking postings...
                    </div>
                ) : (
                    <div className="flex max-h-[60vh] flex-col gap-4 overflow-y-auto">
                        <div className="text-sm text-muted-foreground">
                            Checked {audit.checked_postings} postings.
                            {audit.total_delta !== null && (
                                <>
                                    {' '}
                                    Total difference:{' '}
                                    <span className="font-medium text-foreground tabular-nums">
                                        {formatSharedMoney(
                                            Number(audit.total_delta),
                                            account.currency,
                                            locale,
                                        )}
                                    </span>
                                </>
                            )}
                        </div>

                        {audit.is_consistent ? (
                            <Alert>
                                <AlertTitle>Everything adds up</AlertTitle>
                                <AlertDescription>
                                    No breaks were found in the running balance
                                    chain.
                                </AlertDescription>
                            </Alert>
                        ) : (
                            audit.breaks.map((chainBreak, index) => (
                                <div
                                    key={`${chainBreak.kind}-${chainBreak.date}-${index}`}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="destructive">
                                            {
                                                breakKindLabels[chainBreak.kind]
                                                    .title
                                            }
                                        </Badge>
                                        {chainBreak.date && (
                                            <span className="text-sm text-muted-foreground">
                                                {formatDate(
                                                    chainBreak.date,
                                                    locale,
                                                )}
                                            </span>
                                        )}
                                        {chainBreak.delta !== null && (
                                            <span className="text-sm font-medium tabular-nums">
                                                {formatSharedMoney(
                                                    Number(chainBreak.delta),
                                                    account.currency,
                                                    locale,
                                                )}
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {
                                            breakKindLabels[chainBreak.kind]
                                                .explanation
                                        }
                                    </p>
                                    {chainBreak.postings.length > 0 && (
                                        <table className="mt-2 w-full text-sm">
                                            <tbody>
                                                {chainBreak.postings.map(
                                                    (posting) => (
                                                        <tr
                                                            key={posting.id}
                                                            className="border-t"
                                                        >
                                                            <td className="py-1.5 pr-3 whitespace-nowrap text-muted-foreground tabular-nums">
                                                                {formatDate(
                                                                    posting.date,
                                                                    locale,
                                                                )}
                                                            </td>
                                                            <td className="py-1.5 pr-3">
                                                                {
                                                                    posting.description
                                                                }
                                                            </td>
                                                            <td className="py-1.5 pr-3 text-right whitespace-nowrap tabular-nums">
                                                                {posting.amount ===
                                                                null
                                                                    ? '—'
                                                                    : formatSharedMoney(
                                                                          posting.amount,
                                                                          account.currency,
                                                                          locale,
                                                                      )}
                                                            </td>
                                                            <td className="py-1.5 text-right whitespace-nowrap text-muted-foreground tabular-nums">
                                                                {posting.balance ===
                                                                null
                                                                    ? '—'
                                                                    : formatSharedMoney(
                                                                          posting.balance,
                                                                          account.currency,
                                                                          locale,
                                                                      )}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    )}
                                </div>
                            ))
                        )}
                    </div>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DeletePostingDialog({
    account,
    posting,
    onClose,
}: {
    account: Account;
    posting: Posting | null;
    onClose: () => void;
}) {
    const { locale } = useRegion();

    const remove = () => {
        if (posting === null) {
            return;
        }

        router.delete(
            destroyPosting.url({
                bank_account: account.id,
                posting: posting.id,
            }),
            {
                preserveScroll: true,
                onSuccess: onClose,
            },
        );
    };

    return (
        <Dialog
            open={posting !== null}
            onOpenChange={(nextOpen) => !nextOpen && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete this posting?</DialogTitle>
                    <DialogDescription>
                        {posting === null
                            ? ''
                            : `"${posting.description}" (${posting.original_amount === null ? '—' : formatSharedMoney(posting.original_amount, account.currency, locale)}) will be permanently removed from ${account.name}.`}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={remove}>
                        <Trash2 className="size-4" />
                        Delete posting
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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
