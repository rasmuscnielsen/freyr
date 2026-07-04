import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { CategoryCombobox } from '@/components/category-combobox';
import type { CategoryComboboxOption } from '@/components/category-combobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import {
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
    amount: number;
    currency: string;
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

    const submitFilters = () => {
        const filter: Record<string, string | boolean> = {};

        if (account) {
            filter.account = account;
        }

        if (category) {
            filter.category = category;
        }

        if (type) {
            filter.type = type;
        }

        if (text.trim() !== '') {
            filter.text = text.trim();
        }

        if (from || to) {
            filter.posted_between = `${from},${to}`;
        }

        if (uncategorized) {
            filter.uncategorized = true;
        }

        if (extraordinary) {
            filter.extraordinary = true;
        }

        router.get(
            postingsIndex.url({ query: { filter, sort } }),
            {},
            { preserveState: true, preserveScroll: true },
        );
    };

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
            <div className="flex flex-col gap-4 p-4 md:p-6">
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

                <div className="grid gap-3 rounded-lg border bg-card p-3 md:grid-cols-[1fr_1fr_1fr_1.5fr_auto]">
                    <select
                        value={account}
                        onChange={(event) => setAccount(event.target.value)}
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All accounts</option>
                        {accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.name}
                            </option>
                        ))}
                    </select>
                    <select
                        value={category}
                        onChange={(event) => setCategory(event.target.value)}
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All categories</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                    <select
                        value={type}
                        onChange={(event) => setType(event.target.value)}
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All types</option>
                        <option value="Expense">Expense</option>
                        <option value="Income">Income</option>
                        <option value="Saving">Saving</option>
                        <option value="Exclude">Excluded</option>
                    </select>
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground" />
                        <Input
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    submitFilters();
                                }
                            }}
                            placeholder="Search text"
                            className="pl-9"
                        />
                    </div>
                    <div className="flex gap-2">
                        <Button onClick={submitFilters}>Apply</Button>
                        <Button variant="outline" onClick={clearFilters}>
                            <X className="size-4" />
                        </Button>
                    </div>
                    <div className="flex gap-3 text-sm md:col-span-5">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={uncategorized}
                                onChange={(event) =>
                                    setUncategorized(event.target.checked)
                                }
                            />
                            Uncategorized
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={extraordinary}
                                onChange={(event) =>
                                    setExtraordinary(event.target.checked)
                                }
                            />
                            Extraordinary
                        </label>
                        <label className="flex items-center gap-2">
                            From
                            <Input
                                type="date"
                                value={from}
                                onChange={(event) =>
                                    setFrom(event.target.value)
                                }
                                className="h-8 w-40"
                            />
                        </label>
                        <label className="flex items-center gap-2">
                            To
                            <Input
                                type="date"
                                value={to}
                                onChange={(event) => setTo(event.target.value)}
                                className="h-8 w-40"
                            />
                        </label>
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border bg-card">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[920px] text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Date
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Description
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Account
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Category
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
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
                                        className="border-t outline-none odd:bg-muted/25 focus:bg-accent"
                                    >
                                        <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                            {posting.date}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="font-medium">
                                                {posting.description}
                                            </div>
                                            {posting.is_extraordinary && (
                                                <Badge variant="secondary">
                                                    Extraordinary
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">
                                            {posting.bank_account.name}
                                        </td>
                                        <td className="relative px-3 py-2">
                                            {editingPostingId === posting.id ? (
                                                <CategoryCombobox
                                                    options={comboboxOptions}
                                                    isSaving={
                                                        savingPostingId ===
                                                        posting.id
                                                    }
                                                    onSelect={(subcategoryId) =>
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
                                                        'h-auto justify-start px-2 py-1 text-left',
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
                                        </td>
                                        <td
                                            className={cn(
                                                'px-3 py-2 text-right font-medium whitespace-nowrap',
                                                posting.amount < 0
                                                    ? 'text-orange-600 dark:text-orange-400'
                                                    : 'text-emerald-600 dark:text-emerald-400',
                                            )}
                                        >
                                            {formatMoney(
                                                posting.amount,
                                                posting.currency,
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

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
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                            >
                                <Link href={link.url} preserveScroll>
                                    {index === 0 ? (
                                        <ChevronLeft className="size-4" />
                                    ) : index === postings.links.length - 1 ? (
                                        <ChevronRight className="size-4" />
                                    ) : (
                                        normalizeLabel(link.label)
                                    )}
                                </Link>
                            </Button>
                        ),
                    )}
                </div>
            </div>
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
