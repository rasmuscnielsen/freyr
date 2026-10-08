import { Check, Search, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
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
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

export type PostingsFilterState = {
    account: string;
    category: string;
    type: string;
    text: string;
    from: string;
    to: string;
    uncategorized: boolean;
    extraordinary: boolean;
};

export type FilterAccountOption = {
    id: number;
    name: string;
    bank_name: string | null;
};

export type FilterCategoryOption = {
    id: number;
    name: string;
};

const TYPE_OPTIONS = [
    { value: 'Expense', label: 'Expense' },
    { value: 'Income', label: 'Income' },
    { value: 'Saving', label: 'Saving' },
    { value: 'Exclude', label: 'Excluded' },
];

/**
 * Filters that live inside the mobile sheet (everything except the search
 * text, which stays on the toolbar). The badge count on the Filter button
 * and the sheet's "Clear all" both count exactly these.
 */
export function countSheetFilters(state: PostingsFilterState): number {
    return (
        (state.account !== '' ? 1 : 0) +
        (state.category !== '' ? 1 : 0) +
        (state.type !== '' ? 1 : 0) +
        (state.from !== '' || state.to !== '' ? 1 : 0) +
        (state.uncategorized ? 1 : 0) +
        (state.extraordinary ? 1 : 0)
    );
}

/**
 * The postings filter controls. On desktop (md+) every control sits inline
 * like before; below that breakpoint the row collapses to a search field and
 * a single "Filter" button that opens a bottom sheet holding the same
 * filters — tapping a row applies it immediately.
 */
export function PostingsFilters({
    value,
    accounts,
    categories,
    onChange,
    onTextChange,
    onReset,
    onResetSheetFilters,
}: {
    value: PostingsFilterState;
    accounts: FilterAccountOption[];
    categories: FilterCategoryOption[];
    /** Applies the patch and reloads the list right away. */
    onChange: (patch: Partial<PostingsFilterState>) => void;
    /** Updates the search text only — the page debounces the reload. */
    onTextChange: (text: string) => void;
    /** Clears every filter including the search text. */
    onReset: () => void;
    /** Clears the sheet's filters but keeps the search text. */
    onResetSheetFilters: () => void;
}) {
    const [sheetOpen, setSheetOpen] = useState(false);
    const activeCount = countSheetFilters(value);
    const hasActiveFilters = activeCount > 0 || value.text !== '';

    return (
        <>
            {/* Mobile: search field + one Filter button that opens the sheet. */}
            <div className="flex items-center gap-2 md:hidden">
                <Button
                    variant="outline"
                    size="sm"
                    className="gap-1.5"
                    onClick={() => setSheetOpen(true)}
                    data-test="postings-filters-open"
                >
                    <SlidersHorizontal className="size-4" />
                    Filter
                    {activeCount > 0 && (
                        <Badge variant="secondary" className="px-1.5">
                            {activeCount}
                        </Badge>
                    )}
                </Button>
                <SearchField
                    value={value.text}
                    onChange={onTextChange}
                    className="flex-1"
                />
            </div>

            {/* Desktop: the full filter bar inline. */}
            <div className="hidden flex-wrap items-center gap-2 md:flex">
                <SearchField
                    value={value.text}
                    onChange={onTextChange}
                    className="w-64"
                />
                <Select
                    value={value.account || 'all'}
                    onValueChange={(next) =>
                        onChange({ account: next === 'all' ? '' : next })
                    }
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
                    value={value.category || 'all'}
                    onValueChange={(next) =>
                        onChange({ category: next === 'all' ? '' : next })
                    }
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
                    value={value.type || 'all'}
                    onValueChange={(next) =>
                        onChange({ type: next === 'all' ? '' : next })
                    }
                >
                    <SelectTrigger className="w-32 bg-card">
                        <SelectValue placeholder="All types" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All types</SelectItem>
                        {TYPE_OPTIONS.map((type) => (
                            <SelectItem key={type.value} value={type.value}>
                                {type.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <DateRangeFields
                    from={value.from}
                    to={value.to}
                    onChange={(from, to) => onChange({ from, to })}
                />
                <FilterChip
                    active={value.uncategorized}
                    onClick={() =>
                        onChange({ uncategorized: !value.uncategorized })
                    }
                >
                    Uncategorized
                </FilterChip>
                <FilterChip
                    active={value.extraordinary}
                    onClick={() =>
                        onChange({ extraordinary: !value.extraordinary })
                    }
                >
                    Extraordinary
                </FilterChip>
                {hasActiveFilters && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onReset}
                        className="text-muted-foreground"
                    >
                        Reset
                    </Button>
                )}
            </div>

            <FiltersSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                value={value}
                accounts={accounts}
                categories={categories}
                onChange={onChange}
                onClear={onResetSheetFilters}
                activeCount={activeCount}
            />
        </>
    );
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

function SearchField({
    value,
    onChange,
    className,
}: {
    value: string;
    onChange: (value: string) => void;
    className?: string;
}) {
    return (
        <div className={cn('relative', className)}>
            <Search className="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground" />
            <Input
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder="Search postings…"
                className="bg-card pl-9"
                data-test="postings-search"
            />
        </div>
    );
}

function DateRangeFields({
    from,
    to,
    onChange,
}: {
    from: string;
    to: string;
    onChange: (from: string, to: string) => void;
}) {
    return (
        <div className="flex items-center gap-1.5">
            <Input
                type="date"
                aria-label="From date"
                value={from}
                max={to || undefined}
                onChange={(event) => onChange(event.target.value, to)}
                className="h-9 min-w-0 flex-1 bg-card md:w-[8.75rem] md:flex-none"
            />
            <span className="text-sm text-muted-foreground">–</span>
            <Input
                type="date"
                aria-label="To date"
                value={to}
                min={from || undefined}
                onChange={(event) => onChange(from, event.target.value)}
                className="h-9 min-w-0 flex-1 bg-card md:w-[8.75rem] md:flex-none"
            />
        </div>
    );
}

/**
 * The mobile filter sheet: every control the desktop bar shows inline,
 * grouped into sections. Filters apply on tap so the list updates behind
 * the sheet; "Clear all" resets just the sheet's filters (search stays).
 */
function FiltersSheet({
    open,
    onOpenChange,
    value,
    accounts,
    categories,
    onChange,
    onClear,
    activeCount,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: PostingsFilterState;
    accounts: FilterAccountOption[];
    categories: FilterCategoryOption[];
    onChange: (patch: Partial<PostingsFilterState>) => void;
    onClear: () => void;
    activeCount: number;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                contentClassName="flex flex-col gap-5 p-4"
                data-test="postings-filters-sheet"
                header={
                    <SheetHeader className="flex-row items-center justify-between gap-2 p-0 px-4 pt-2 pb-3">
                        <SheetTitle>Filters</SheetTitle>
                        {activeCount > 0 && (
                            <button
                                type="button"
                                className="text-xs text-muted-foreground underline-offset-2 hover:underline"
                                onClick={onClear}
                                data-test="postings-filters-clear"
                            >
                                Clear all ({activeCount})
                            </button>
                        )}
                    </SheetHeader>
                }
            >
                <section className="flex flex-col gap-2">
                    <FilterSectionLabel>Show only</FilterSectionLabel>
                    <ChecklistRow
                        checked={value.uncategorized}
                        onChange={() =>
                            onChange({ uncategorized: !value.uncategorized })
                        }
                        dataTest="postings-filter-uncategorized"
                    >
                        Uncategorized
                    </ChecklistRow>
                    <ChecklistRow
                        checked={value.extraordinary}
                        onChange={() =>
                            onChange({ extraordinary: !value.extraordinary })
                        }
                        dataTest="postings-filter-extraordinary"
                    >
                        Extraordinary
                    </ChecklistRow>
                </section>

                <section className="flex flex-col gap-2">
                    <FilterSectionLabel>Date range</FilterSectionLabel>
                    <DateRangeFields
                        from={value.from}
                        to={value.to}
                        onChange={(from, to) => onChange({ from, to })}
                    />
                </section>

                <section className="flex flex-col gap-1">
                    <FilterSectionLabel>Account</FilterSectionLabel>
                    <div
                        role="radiogroup"
                        aria-label="Account"
                        className="max-h-56 overflow-y-auto"
                    >
                        <RadioRow
                            selected={value.account === ''}
                            onSelect={() => onChange({ account: '' })}
                        >
                            All accounts
                        </RadioRow>
                        {accounts.map((account) => (
                            <RadioRow
                                key={account.id}
                                selected={value.account === String(account.id)}
                                onSelect={() =>
                                    onChange({ account: String(account.id) })
                                }
                                dataTest={`postings-filter-account-${account.id}`}
                            >
                                <span className="truncate">{account.name}</span>
                                {account.bank_name !== null && (
                                    <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                        {account.bank_name}
                                    </span>
                                )}
                            </RadioRow>
                        ))}
                    </div>
                </section>

                <section className="flex flex-col gap-1">
                    <FilterSectionLabel>Category</FilterSectionLabel>
                    <div
                        role="radiogroup"
                        aria-label="Category"
                        className="max-h-56 overflow-y-auto"
                    >
                        <RadioRow
                            selected={value.category === ''}
                            onSelect={() => onChange({ category: '' })}
                        >
                            All categories
                        </RadioRow>
                        {categories.map((category) => (
                            <RadioRow
                                key={category.id}
                                selected={
                                    value.category === String(category.id)
                                }
                                onSelect={() =>
                                    onChange({ category: String(category.id) })
                                }
                                dataTest={`postings-filter-category-${category.id}`}
                            >
                                {category.name}
                            </RadioRow>
                        ))}
                    </div>
                </section>

                <section className="flex flex-col gap-1">
                    <FilterSectionLabel>Type</FilterSectionLabel>
                    <div role="radiogroup" aria-label="Type">
                        <RadioRow
                            selected={value.type === ''}
                            onSelect={() => onChange({ type: '' })}
                        >
                            All types
                        </RadioRow>
                        {TYPE_OPTIONS.map((type) => (
                            <RadioRow
                                key={type.value}
                                selected={value.type === type.value}
                                onSelect={() => onChange({ type: type.value })}
                                dataTest={`postings-filter-type-${type.value}`}
                            >
                                {type.label}
                            </RadioRow>
                        ))}
                    </div>
                </section>

                <SheetClose asChild>
                    <Button
                        variant="secondary"
                        className="mt-auto w-full"
                        data-test="postings-filters-done"
                    >
                        Done
                    </Button>
                </SheetClose>
            </SheetContent>
        </Sheet>
    );
}

function FilterSectionLabel({ children }: { children: React.ReactNode }) {
    return (
        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {children}
        </p>
    );
}

function ChecklistRow({
    checked,
    onChange,
    dataTest,
    children,
}: {
    checked: boolean;
    onChange: () => void;
    dataTest?: string;
    children: React.ReactNode;
}) {
    return (
        <div
            role="checkbox"
            aria-checked={checked}
            tabIndex={0}
            onClick={onChange}
            onKeyDown={(event) => {
                if (event.key === ' ' || event.key === 'Enter') {
                    event.preventDefault();
                    onChange();
                }
            }}
            className="flex min-h-11 items-center gap-3 rounded-md px-2 text-left text-sm hover:bg-muted/60"
            data-test={dataTest}
        >
            <Checkbox checked={checked} className="pointer-events-none" />
            <span className="flex min-w-0 flex-1 items-center gap-1.5">
                {children}
            </span>
        </div>
    );
}

/**
 * Single-select row with an iOS-style trailing checkmark — the sheet's
 * account/category/type filters each pick exactly one option.
 */
function RadioRow({
    selected,
    onSelect,
    dataTest,
    children,
}: {
    selected: boolean;
    onSelect: () => void;
    dataTest?: string;
    children: React.ReactNode;
}) {
    return (
        <div
            role="radio"
            aria-checked={selected}
            tabIndex={0}
            onClick={onSelect}
            onKeyDown={(event) => {
                if (event.key === ' ' || event.key === 'Enter') {
                    event.preventDefault();
                    onSelect();
                }
            }}
            className={cn(
                'flex min-h-11 items-center gap-3 rounded-md px-2 text-left text-sm hover:bg-muted/60',
                selected && 'font-medium',
            )}
            data-test={dataTest}
        >
            <span className="flex min-w-0 flex-1 items-center gap-1.5">
                {children}
            </span>
            {selected && <Check className="size-4 shrink-0 text-brand" />}
        </div>
    );
}
