import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    ChevronsUpDown,
    Search,
    StarIcon,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CategoryCombobox } from '@/components/postings/category-combobox';
import type { CategoryComboboxOption } from '@/components/postings/category-combobox';
import { PostingFormDialog } from '@/components/postings/posting-form-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate, formatDayMonth } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { useRegion } from '@/lib/region';
import { cn } from '@/lib/utils';
import {
    bulkUpdate as bulkUpdatePostings,
    index as postingsIndex,
    update as updatePosting,
} from '@/routes/postings';

type Account = {
    id: number;
    name: string;
    bank_name: string | null;
    currency: string;
    is_active: boolean;
};

type SubcategoryOption = {
    id: number;
    name: string;
    type: string;
    categoryId: number;
    categoryName: string;
    label: string;
};

type Category = {
    id: number;
    name: string;
    type: string;
    subcategories: Array<{
        id: number;
        name: string;
        type: string;
    }>;
};

type Posting = {
    id: number;
    date: string;
    description: string;
    original_description: string | null;
    comment: string | null;
    amount: number | null;
    currency: string;
    original_amount: number | null;
    original_currency: string | null;
    is_extraordinary: boolean;
    bank_account: {
        id: number;
        name: string;
        bank_name: string | null;
    };
    category: {
        id: number;
        name: string;
        type: string;
    } | null;
    subcategory: {
        id: number;
        name: string;
        type: string;
    } | null;
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

type PostingsProps = {
    postings: Paginator<Posting>;
    filters: Record<string, string | number | boolean | undefined>;
    sort: string;
    accounts: Account[];
    categories: Category[];
};

function normalizeLabel(label: string): string {
    return label.replace('&laquo;', 'Previous').replace('&raquo;', 'Next');
}

function FilterChip({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'inline-flex h-9 items-center rounded-full border px-3.5 text-sm transition-colors',
                active
                    ? 'border-brand/30 bg-brand/10 font-medium text-brand'
                    : 'bg-card text-muted-foreground hover:bg-accent hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}

function focusRow(index: number): void {
    window.requestAnimationFrame(() => {
        const row = document.querySelector<HTMLElement>(
            `[data-posting-row="${index}"]`,
        );

        row?.focus();
    });
}

/**
 * Finds the row index of the next uncategorized posting at or after `fromIndex`
 * for fast triage. Falls back to the next row, then the given row, so focus is
 * always predictable.
 */
function nextTriageRowIndex(postings: Posting[], fromIndex: number): number {
    for (let index = fromIndex; index < postings.length; index += 1) {
        if (!postings[index]?.subcategory) {
            return index;
        }
    }

    if (fromIndex < postings.length) {
        return fromIndex;
    }

    return Math.max(postings.length - 1, 0);
}

export default function Postings({
    postings,
    filters,
    sort,
    accounts,
    categories,
}: PostingsProps) {
    const [account, setAccount] = useState(String(filters.account ?? ''));
    const [category, setCategory] = useState(String(filters.category ?? ''));
    const [type, setType] = useState(String(filters.type ?? ''));
    const [text, setText] = useState(String(filters.text ?? ''));
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [uncategorized, setUncategorized] = useState(
        filters.uncategorized === true || filters.uncategorized === '1',
    );
    const [extraordinary, setExtraordinary] = useState(
        filters.extraordinary === true || filters.extraordinary === '1',
    );
    const [editingPostingId, setEditingPostingId] = useState<number | null>(
        null,
    );
    const [savingPostingId, setSavingPostingId] = useState<number | null>(null);
    const [modalPosting, setModalPosting] = useState<Posting | null>(null);
    const [rawSelectedIds, setRawSelectedIds] = useState<Set<number>>(
        new Set(),
    );
    const { locale } = useRegion();
    const [isPickingBulkCategory, setIsPickingBulkCategory] = useState(false);
    const [isBulkSaving, setIsBulkSaving] = useState(false);
    // Anchor for shift-click range selection.
    const lastToggledIndex = useRef<number | null>(null);

    // Selection is scoped to the visible rows: ids that left the result set
    // (filtering, pagination, or a reload after saving) drop out on their own.
    const selectedIds = useMemo(() => {
        const visible = new Set(postings.data.map((posting) => posting.id));

        return new Set([...rawSelectedIds].filter((id) => visible.has(id)));
    }, [rawSelectedIds, postings.data]);

    const subcategoryOptions = useMemo<SubcategoryOption[]>(
        () =>
            categories.flatMap((category) =>
                category.subcategories.map((subcategory) => ({
                    id: subcategory.id,
                    name: subcategory.name,
                    type: subcategory.type,
                    categoryId: category.id,
                    categoryName: category.name,
                    label: `${category.name} / ${subcategory.name}`,
                })),
            ),
        [categories],
    );

    const comboboxOptions = useMemo<CategoryComboboxOption[]>(
        () =>
            subcategoryOptions.map((option) => ({
                id: option.id,
                label: option.label,
            })),
        [subcategoryOptions],
    );

    type FilterState = {
        account: string;
        category: string;
        type: string;
        text: string;
        from: string;
        to: string;
        uncategorized: boolean;
        extraordinary: boolean;
    };

    // Filters apply as soon as a control changes; free-text search is
    // debounced below. Pass the changed value in as an override, since the
    // matching state update has not landed yet when the handler runs.
    const submitFilters = (overrides: Partial<FilterState> = {}) => {
        const state: FilterState = {
            account,
            category,
            type,
            text,
            from,
            to,
            uncategorized,
            extraordinary,
            ...overrides,
        };

        const filter: Record<string, string | boolean> = {};

        if (state.account) {
            filter.account = state.account;
        }

        if (state.category) {
            filter.category = state.category;
        }

        if (state.type) {
            filter.type = state.type;
        }

        if (state.text.trim() !== '') {
            filter.text = state.text.trim();
        }

        if (state.from || state.to) {
            filter.posted_between = `${state.from},${state.to}`;
        }

        if (state.uncategorized) {
            filter.uncategorized = true;
        }

        if (state.extraordinary) {
            filter.extraordinary = true;
        }

        router.get(
            postingsIndex.url({ query: { filter, sort } }),
            {},
            { preserveState: true, preserveScroll: true },
        );
    };

    useEffect(() => {
        if (text.trim() === String(filters.text ?? '').trim()) {
            return;
        }

        const timeout = setTimeout(() => submitFilters(), 350);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [text, filters.text]);

    const clearFilters = () => {
        setAccount('');
        setCategory('');
        setType('');
        setText('');
        setFrom('');
        setTo('');
        setUncategorized(false);
        setExtraordinary(false);
        router.get(postingsIndex.url(), {}, { preserveState: true });
    };

    const hasActiveFilters =
        account !== '' ||
        category !== '' ||
        type !== '' ||
        text !== '' ||
        from !== '' ||
        to !== '' ||
        uncategorized ||
        extraordinary;

    const applyCategory = (
        posting: Posting,
        subcategoryId: number | null,
        rowIndex: number,
    ) => {
        setSavingPostingId(posting.id);

        router.patch(
            updatePosting.url(posting.id),
            { categorized_subcategory_id: subcategoryId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingPostingId(null);
                    // Advance to the next uncategorized row for fast triage,
                    // starting after the row we just categorized.
                    focusRow(nextTriageRowIndex(postings.data, rowIndex + 1));
                },
                onFinish: () => {
                    setSavingPostingId(null);
                },
            },
        );
    };

    const toggleRowSelection = (rowIndex: number, shiftKey: boolean) => {
        const posting = postings.data[rowIndex];

        if (!posting) {
            return;
        }

        setRawSelectedIds((previous) => {
            const next = new Set(previous);
            const selecting = !previous.has(posting.id);
            const anchor =
                shiftKey && lastToggledIndex.current !== null
                    ? lastToggledIndex.current
                    : rowIndex;
            const start = Math.min(anchor, rowIndex);
            const end = Math.max(anchor, rowIndex);

            for (let index = start; index <= end; index += 1) {
                const id = postings.data[index]?.id;

                if (id === undefined) {
                    continue;
                }

                if (selecting) {
                    next.add(id);
                } else {
                    next.delete(id);
                }
            }

            return next;
        });

        lastToggledIndex.current = rowIndex;
    };

    const allRowsSelected =
        postings.data.length > 0 &&
        postings.data.every((posting) => selectedIds.has(posting.id));

    const toggleSelectAll = () => {
        setRawSelectedIds(
            allRowsSelected
                ? new Set()
                : new Set(postings.data.map((posting) => posting.id)),
        );
        lastToggledIndex.current = null;
    };

    const clearSelection = () => {
        setRawSelectedIds(new Set());
        setIsPickingBulkCategory(false);
        lastToggledIndex.current = null;
    };

    const applyBulkCategory = (subcategoryId: number | null) => {
        setIsBulkSaving(true);

        router.patch(
            bulkUpdatePostings.url(),
            {
                ids: [...selectedIds],
                categorized_subcategory_id: subcategoryId,
            },
            {
                preserveScroll: true,
                onSuccess: () => clearSelection(),
                onFinish: () => setIsBulkSaving(false),
            },
        );
    };

    const rowKeyDown = (
        event: React.KeyboardEvent<HTMLTableRowElement>,
        posting: Posting,
        rowIndex: number,
    ) => {
        const target = event.target as HTMLElement;

        if (['BUTTON', 'INPUT', 'SELECT'].includes(target.tagName)) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            focusRow(Math.min(rowIndex + 1, postings.data.length - 1));
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            focusRow(Math.max(rowIndex - 1, 0));
        }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            setEditingPostingId(posting.id);
        }
    };

    return (
        <>
            <Head title="Postings" />
            <div
                className={cn(
                    'flex flex-col gap-4 p-4 md:p-6',
                    // Room for the floating selection bar so it never covers
                    // the pagination while a selection is active.
                    selectedIds.size > 0 && 'pb-20',
                )}
            >
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Postings
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Categorize transactions and inspect account
                            activity.
                        </p>
                    </div>
                    <div className="text-sm text-muted-foreground">
                        {postings.from ?? 0}-{postings.to ?? 0} of{' '}
                        {postings.total}
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative w-full sm:w-64">
                        <Search className="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground" />
                        <Input
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    submitFilters();
                                }
                            }}
                            placeholder="Search postings…"
                            className="bg-card pl-9"
                        />
                    </div>
                    <Select
                        value={account || 'all'}
                        onValueChange={(value) => {
                            const next = value === 'all' ? '' : value;
                            setAccount(next);
                            submitFilters({ account: next });
                        }}
                    >
                        <SelectTrigger className="w-44 bg-card">
                            <SelectValue placeholder="All accounts" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All accounts</SelectItem>
                            {accounts.map((account) => (
                                <SelectItem
                                    key={account.id}
                                    value={String(account.id)}
                                >
                                    {account.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={category || 'all'}
                        onValueChange={(value) => {
                            const next = value === 'all' ? '' : value;
                            setCategory(next);
                            submitFilters({ category: next });
                        }}
                    >
                        <SelectTrigger className="w-44 bg-card">
                            <SelectValue placeholder="All categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All categories</SelectItem>
                            {categories.map((category) => (
                                <SelectItem
                                    key={category.id}
                                    value={String(category.id)}
                                >
                                    {category.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={type || 'all'}
                        onValueChange={(value) => {
                            const next = value === 'all' ? '' : value;
                            setType(next);
                            submitFilters({ type: next });
                        }}
                    >
                        <SelectTrigger className="w-32 bg-card">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            <SelectItem value="Expense">Expense</SelectItem>
                            <SelectItem value="Income">Income</SelectItem>
                            <SelectItem value="Saving">Saving</SelectItem>
                            <SelectItem value="Exclude">Excluded</SelectItem>
                        </SelectContent>
                    </Select>
                    <div className="flex items-center gap-1.5">
                        <Input
                            type="date"
                            aria-label="From date"
                            value={from}
                            onChange={(event) => {
                                setFrom(event.target.value);
                                submitFilters({ from: event.target.value });
                            }}
                            className="h-9 w-[8.75rem] bg-card"
                        />
                        <span className="text-sm text-muted-foreground">–</span>
                        <Input
                            type="date"
                            aria-label="To date"
                            value={to}
                            onChange={(event) => {
                                setTo(event.target.value);
                                submitFilters({ to: event.target.value });
                            }}
                            className="h-9 w-[8.75rem] bg-card"
                        />
                    </div>
                    <FilterChip
                        active={uncategorized}
                        onClick={() => {
                            const next = !uncategorized;
                            setUncategorized(next);
                            submitFilters({ uncategorized: next });
                        }}
                    >
                        Uncategorized
                    </FilterChip>
                    <FilterChip
                        active={extraordinary}
                        onClick={() => {
                            const next = !extraordinary;
                            setExtraordinary(next);
                            submitFilters({ extraordinary: next });
                        }}
                    >
                        Extraordinary
                    </FilterChip>
                    {hasActiveFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={clearFilters}
                            className="text-muted-foreground"
                        >
                            Reset
                        </Button>
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border bg-card shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm md:min-w-[720px]">
                            <thead className="text-left">
                                <tr>
                                    <th className="w-10 py-2.5 pl-3">
                                        <Checkbox
                                            aria-label="Select all postings on this page"
                                            checked={
                                                allRowsSelected
                                                    ? true
                                                    : selectedIds.size > 0
                                                      ? 'indeterminate'
                                                      : false
                                            }
                                            onCheckedChange={toggleSelectAll}
                                        />
                                    </th>
                                    <th className="hidden px-3 py-2.5 text-xs font-medium text-muted-foreground md:table-cell">
                                        Date
                                    </th>
                                    <th className="px-3 py-2.5 text-xs font-medium text-muted-foreground">
                                        Description
                                    </th>
                                    <th className="hidden px-3 py-2.5 text-xs font-medium text-muted-foreground lg:table-cell">
                                        Account
                                    </th>
                                    <th className="hidden px-3 py-2.5 text-xs font-medium text-muted-foreground md:table-cell">
                                        Category
                                    </th>
                                    <th className="px-3 py-2.5 text-right text-xs font-medium text-muted-foreground">
                                        Amount
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {postings.data.map((posting, rowIndex) => (
                                    <tr
                                        key={posting.id}
                                        tabIndex={0}
                                        data-posting-row={rowIndex}
                                        onKeyDown={(event) =>
                                            rowKeyDown(event, posting, rowIndex)
                                        }
                                        onClick={() => setModalPosting(posting)}
                                        className={cn(
                                            'cursor-pointer border-t transition-colors outline-none hover:bg-muted/40 focus:bg-accent',
                                            selectedIds.has(posting.id) &&
                                                'bg-brand/5',
                                        )}
                                    >
                                        <td
                                            className="w-10 py-2 pl-3"
                                            onClick={(event) =>
                                                event.stopPropagation()
                                            }
                                        >
                                            <Checkbox
                                                aria-label={`Select ${posting.description}`}
                                                checked={selectedIds.has(
                                                    posting.id,
                                                )}
                                                // Shift-click selects the range
                                                // from the last toggled row.
                                                onClick={(event) =>
                                                    toggleRowSelection(
                                                        rowIndex,
                                                        event.shiftKey,
                                                    )
                                                }
                                                onMouseDown={(event) => {
                                                    if (event.shiftKey) {
                                                        event.preventDefault();
                                                    }
                                                }}
                                            />
                                        </td>
                                        <td className="hidden px-3 py-2 whitespace-nowrap text-muted-foreground tabular-nums md:table-cell">
                                            {formatDate(posting.date, locale)}
                                        </td>
                                        <td className="px-3 py-2">
                                            {/* The date column is hidden on
                                                small screens so the amount stays
                                                in view; surface it here instead. */}
                                            <div className="text-xs text-muted-foreground md:hidden">
                                                {formatDayMonth(
                                                    posting.date,
                                                    locale,
                                                )}
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <div className="font-medium">
                                                    {posting.description}
                                                </div>
                                                {posting.is_extraordinary && (
                                                    <StarIcon className="size-4 shrink-0 fill-current" />
                                                )}
                                            </div>
                                            {/* The category column is hidden on
                                                small screens so the amount stays
                                                in view; surface it here instead. */}
                                            <div
                                                className={cn(
                                                    'text-xs md:hidden',
                                                    posting.subcategory
                                                        ? 'text-muted-foreground'
                                                        : 'text-muted-foreground/70 italic',
                                                )}
                                            >
                                                {posting.subcategory
                                                    ? `${posting.category?.name} / ${posting.subcategory.name}`
                                                    : 'Uncategorized'}
                                            </div>
                                        </td>
                                        <td className="hidden px-3 py-2 text-muted-foreground lg:table-cell">
                                            {posting.bank_account.name}
                                        </td>
                                        <td
                                            className="relative hidden px-3 py-2 md:table-cell"
                                            onClick={(event) =>
                                                event.stopPropagation()
                                            }
                                        >
                                            {/* Reserve the trigger's height so swapping to
                                                the (absolutely-positioned) combobox never
                                                shifts the row. */}
                                            <div className="flex min-h-8 items-center">
                                                {editingPostingId ===
                                                posting.id ? (
                                                    <CategoryCombobox
                                                        options={
                                                            comboboxOptions
                                                        }
                                                        isSaving={
                                                            savingPostingId ===
                                                            posting.id
                                                        }
                                                        onSelect={(
                                                            subcategoryId,
                                                        ) =>
                                                            applyCategory(
                                                                posting,
                                                                subcategoryId,
                                                                rowIndex,
                                                            )
                                                        }
                                                        onClose={() => {
                                                            setEditingPostingId(
                                                                null,
                                                            );
                                                            focusRow(rowIndex);
                                                        }}
                                                    />
                                                ) : (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        aria-haspopup="listbox"
                                                        className={cn(
                                                            'h-8 justify-start px-2 py-1 text-left',
                                                            !posting.subcategory &&
                                                                'text-muted-foreground',
                                                        )}
                                                        onClick={() =>
                                                            setEditingPostingId(
                                                                posting.id,
                                                            )
                                                        }
                                                    >
                                                        {posting.subcategory
                                                            ? `${posting.category?.name} / ${posting.subcategory.name}`
                                                            : 'Uncategorized'}
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                        <td
                                            className={cn(
                                                'px-3 py-2 text-right font-medium whitespace-nowrap tabular-nums',
                                                (posting.amount ??
                                                    posting.original_amount ??
                                                    0) >= 0 &&
                                                    'text-chart-income',
                                            )}
                                        >
                                            {posting.amount !== null ? (
                                                formatMoney(
                                                    posting.amount,
                                                    posting.currency,
                                                    locale,
                                                )
                                            ) : posting.original_amount !==
                                              null ? (
                                                <span className="inline-flex items-baseline gap-1">
                                                    {formatMoney(
                                                        posting.original_amount,
                                                        posting.original_currency,
                                                        locale,
                                                    )}
                                                    <span className="text-xs font-normal text-muted-foreground">
                                                        pending
                                                    </span>
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {selectedIds.size > 0 && (
                    <div className="fixed bottom-4 z-40 flex min-w-xs items-center gap-1.5 rounded-full border bg-card py-3 pr-1.5 pl-4 whitespace-nowrap shadow-lg max-sm:inset-x-2 md:left-1/2 md:-translate-x-1/2">
                        <span className="flex-1 pr-1 text-sm font-medium tabular-nums">
                            {selectedIds.size} selected
                        </span>
                        <div className="relative">
                            <Button
                                size="sm"
                                aria-haspopup="listbox"
                                className="rounded-full"
                                disabled={isBulkSaving}
                                onClick={() => setIsPickingBulkCategory(true)}
                            >
                                {isBulkSaving ? 'Saving…' : 'Set category'}
                                <ChevronsUpDown className="opacity-50" />
                            </Button>
                            {isPickingBulkCategory && (
                                <CategoryCombobox
                                    options={comboboxOptions}
                                    isSaving={isBulkSaving}
                                    onSelect={applyBulkCategory}
                                    onClose={() =>
                                        setIsPickingBulkCategory(false)
                                    }
                                />
                            )}
                        </div>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Clear selection"
                            className="size-7 rounded-full"
                            disabled={isBulkSaving}
                            onClick={clearSelection}
                        >
                            <X className="size-3.5" />
                        </Button>
                    </div>
                )}

                {/* Prev + one page + next means there is nothing to paginate. */}
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
            </div>

            <PostingFormDialog
                key={
                    modalPosting === null
                        ? 'posting-modal-closed'
                        : `posting-modal-${modalPosting.id}`
                }
                open={modalPosting !== null}
                onClose={() => setModalPosting(null)}
                title="Edit posting"
                description="Categorize the posting, add a comment, or mark it extraordinary. Date, description, and amount are managed on the account."
                currency={
                    modalPosting?.original_currency ??
                    modalPosting?.currency ??
                    'DKK'
                }
                categoryOptions={comboboxOptions}
                initial={{
                    date: modalPosting?.date ?? '',
                    description: modalPosting?.description ?? '',
                    amount:
                        (
                            modalPosting?.original_amount ??
                            modalPosting?.amount
                        )?.toFixed(2) ?? '',
                    categorized_subcategory_id:
                        modalPosting?.subcategory?.id ?? null,
                    comment: modalPosting?.comment ?? '',
                    is_extraordinary: modalPosting?.is_extraordinary ?? false,
                }}
                coreFieldsEditable={false}
                action={{
                    method: 'patch',
                    url: updatePosting.url(modalPosting?.id ?? 0),
                }}
            />
        </>
    );
}

Postings.layout = {
    breadcrumbs: [
        {
            title: 'Postings',
            href: postingsIndex(),
        },
    ],
};
