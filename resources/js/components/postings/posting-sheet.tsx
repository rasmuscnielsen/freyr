import { useForm } from '@inertiajs/react';
import { ChevronsUpDown, Sparkles, StarIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { CategoryCombobox } from '@/components/postings/category-combobox';
import type { CategoryComboboxOption } from '@/components/postings/category-combobox';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDayMonth } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { useRegion } from '@/lib/region';
import { cn } from '@/lib/utils';
import { similar as postingsSimilar, update as updatePosting } from '@/routes/postings';

export type SheetPosting = {
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
    categorized_by: string | null;
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
    suggested_subcategory: {
        id: number;
        name: string;
        category_name: string | null;
    } | null;
};

type SimilarMatch = SheetPosting & { similarity: number };

type SheetDetail = {
    posting: SheetPosting;
    similar: SimilarMatch[];
    similarity_enabled: boolean;
};

type PostingFormValues = {
    categorized_subcategory_id: number | null;
    comment: string;
    is_extraordinary: boolean;
};

const STACK_OFFSET_PX = 16;

async function fetchDetail(postingId: number): Promise<SheetDetail> {
    const response = await fetch(postingsSimilar.url(postingId), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error('Failed to load posting');
    }

    return response.json();
}

function formatCategorizedBy(by: string | null): string | null {
    switch (by) {
        case 'similarity':
            return 'Auto-categorized by similarity';
        case 'import':
            return 'Categorized on import';
        default:
            return null;
    }
}

/**
 * A single level in the stacked sheet: posting details, category editing and
 * similar postings. Each level fetches its own detail lazily.
 */
function SheetLevel({
    postingId,
    isTop,
    categoryOptions,
    onOpenPosting,
}: {
    postingId: number;
    isTop: boolean;
    categoryOptions: CategoryComboboxOption[];
    onOpenPosting: (postingId: number) => void;
}) {
    const { locale } = useRegion();
    const [detail, setDetail] = useState<SheetDetail | null>(null);
    const [loadFailed, setLoadFailed] = useState(false);
    const [isPickingCategory, setIsPickingCategory] = useState(false);
    const form = useForm<PostingFormValues>({
        categorized_subcategory_id: null,
        comment: '',
        is_extraordinary: false,
    });

    useEffect(() => {
        let cancelled = false;

        fetchDetail(postingId)
            .then((result) => {
                if (cancelled) {
                    return;
                }

                setDetail(result);
                form.setData({
                    categorized_subcategory_id:
                        result.posting.subcategory?.id ?? null,
                    comment: result.posting.comment ?? '',
                    is_extraordinary: result.posting.is_extraordinary,
                });
            })
            .catch(() => !cancelled && setLoadFailed(true));

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [postingId]);

    const submit = () => {
        form.transform((data) => ({
            ...data,
            comment: data.comment.trim() === '' ? null : data.comment,
        }));
        form.patch(updatePosting.url(postingId), {
            preserveScroll: true,
        });
    };

    const applySuggestion = (subcategoryId: number) => {
        form.setData('categorized_subcategory_id', subcategoryId);
        form.transform((data) => ({
            ...data,
            categorized_subcategory_id: subcategoryId,
            comment: data.comment.trim() === '' ? null : data.comment,
        }));
        form.patch(updatePosting.url(postingId), {
            preserveScroll: true,
        });
    };

    const selectedCategoryLabel =
        categoryOptions.find(
            (option) => option.id === form.data.categorized_subcategory_id,
        )?.label ?? null;

    const posting = detail?.posting ?? null;
    const suggestion = posting?.suggested_subcategory ?? null;
    const showSuggestion =
        suggestion !== null && form.data.categorized_subcategory_id === null;
    const categorizedBy = formatCategorizedBy(posting?.categorized_by ?? null);

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex min-w-0 flex-col gap-1 border-b p-4 pr-12">
                {posting === null ? (
                    <div className="flex h-16 items-center justify-center">
                        {loadFailed ? (
                            <p className="text-sm text-muted-foreground">
                                Could not load this posting.
                            </p>
                        ) : (
                            <Spinner />
                        )}
                    </div>
                ) : (
                    <>
                        <div className="flex min-w-0 items-baseline justify-between gap-4">
                            <span className="flex min-w-0 items-center gap-1.5 font-medium wrap-anywhere">
                                <span className="min-w-0">
                                    {posting.description}
                                </span>
                                {posting.is_extraordinary && (
                                    <StarIcon className="size-4 shrink-0 fill-current" />
                                )}
                            </span>
                            <span
                                className={cn(
                                    'shrink-0 font-medium tabular-nums',
                                    (posting.amount ??
                                        posting.original_amount ??
                                        0) >= 0 && 'text-chart-income',
                                )}
                            >
                                {posting.amount !== null
                                    ? formatMoney(
                                          posting.amount,
                                          posting.currency,
                                          locale,
                                      )
                                    : posting.original_amount !== null
                                      ? formatMoney(
                                            posting.original_amount,
                                            posting.original_currency,
                                            locale,
                                        )
                                      : '—'}
                            </span>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {formatDate(posting.date, locale)} ·{' '}
                            {posting.bank_account.name}
                        </p>
                    </>
                )}
            </div>

            {posting !== null && (
                <div className="min-h-0 flex-1 overflow-y-auto p-4">
                    <div className="grid gap-5">
                        {showSuggestion && (
                            <div className="grid gap-2 rounded-md border border-brand/30 bg-brand/5 p-3">
                                <p className="flex items-center gap-1.5 text-sm font-medium">
                                    <Sparkles className="size-4 text-brand" />
                                    Suggested category
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {suggestion.category_name} /{' '}
                                    {suggestion.name}
                                </p>
                                <div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={form.processing}
                                        onClick={() =>
                                            applySuggestion(suggestion.id)
                                        }
                                        data-test="apply-suggestion"
                                    >
                                        Apply suggestion
                                    </Button>
                                </div>
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label>Category</Label>
                            <div className="relative">
                                <Button
                                    type="button"
                                    variant="outline"
                                    aria-haspopup="listbox"
                                    className={cn(
                                        'w-full justify-between font-normal',
                                        selectedCategoryLabel === null &&
                                            'text-muted-foreground',
                                    )}
                                    onClick={() => setIsPickingCategory(true)}
                                >
                                    {selectedCategoryLabel ?? 'Uncategorized'}
                                    <ChevronsUpDown className="size-4 opacity-50" />
                                </Button>
                                {isPickingCategory && (
                                    <CategoryCombobox
                                        options={categoryOptions}
                                        onSelect={(subcategoryId) => {
                                            form.setData(
                                                'categorized_subcategory_id',
                                                subcategoryId,
                                            );
                                            setIsPickingCategory(false);
                                        }}
                                        onClose={() =>
                                            setIsPickingCategory(false)
                                        }
                                    />
                                )}
                            </div>
                            {categorizedBy !== null && (
                                <p className="text-xs text-muted-foreground">
                                    {categorizedBy}
                                </p>
                            )}
                            <InputError
                                message={form.errors.categorized_subcategory_id}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor={`posting-comment-${postingId}`}>
                                Comment
                            </Label>
                            <Input
                                id={`posting-comment-${postingId}`}
                                placeholder="Optional note"
                                value={form.data.comment}
                                onChange={(event) =>
                                    form.setData('comment', event.target.value)
                                }
                            />
                            <InputError message={form.errors.comment} />
                        </div>

                        <div className="flex items-start gap-3 rounded-md border p-3">
                            <Checkbox
                                id={`posting-extraordinary-${postingId}`}
                                checked={form.data.is_extraordinary}
                                onCheckedChange={(value) =>
                                    form.setData(
                                        'is_extraordinary',
                                        value === true,
                                    )
                                }
                            />
                            <div className="grid gap-1">
                                <Label
                                    htmlFor={`posting-extraordinary-${postingId}`}
                                >
                                    Extraordinary
                                </Label>
                                <p className="text-sm text-muted-foreground">
                                    Kept out of the overview so one-off amounts
                                    don't skew your numbers.
                                </p>
                            </div>
                        </div>

                        <Button
                            type="button"
                            onClick={submit}
                            disabled={form.processing}
                            data-test="save-posting"
                        >
                            {form.processing ? 'Saving…' : 'Save changes'}
                        </Button>

                        {detail !== null &&
                            detail.similarity_enabled &&
                            detail.similar.length > 0 && (
                                <div className="grid gap-2 border-t pt-4">
                                    <p className="text-sm font-medium">
                                        Similar postings
                                    </p>
                                    <div className="grid gap-1">
                                        {detail.similar.map((match) => (
                                            <button
                                                key={match.id}
                                                type="button"
                                                disabled={!isTop}
                                                onClick={() =>
                                                    onOpenPosting(match.id)
                                                }
                                                className="flex min-w-0 items-center gap-2 rounded-md border px-3 py-2 text-left text-sm transition-colors hover:bg-accent"
                                                data-test="similar-posting"
                                            >
                                                <span className="min-w-0 flex-1 truncate">
                                                    {match.description}
                                                </span>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {formatDayMonth(
                                                        match.date,
                                                        locale,
                                                    )}
                                                </span>
                                                <span className="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium tabular-nums">
                                                    {Math.round(
                                                        match.similarity * 100,
                                                    )}
                                                    %
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Click one to open it on top of this
                                        sheet.
                                    </p>
                                </div>
                            )}
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * Posting detail sheet with stacking: clicking a similar posting pushes a new
 * level on top, and each level below peeks out as a strip on the left. Click a
 * strip to jump back to that level.
 */
export function PostingSheet({
    postingId,
    onClose,
    categoryOptions,
}: {
    postingId: number | null;
    onClose: () => void;
    categoryOptions: CategoryComboboxOption[];
}) {
    const [stack, setStack] = useState<number[]>([]);
    const [openId, setOpenId] = useState<number | null>(null);

    // Reset the stack when a different posting is opened (render-phase
    // adjustment — React's sanctioned alternative to syncing via an effect).
    if (postingId !== openId) {
        setOpenId(postingId);
        setStack(postingId === null ? [] : [postingId]);
    }

    return (
        <Sheet
            open={postingId !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent
                className="w-full gap-0 overflow-hidden bg-transparent p-0 shadow-none sm:max-w-xl"
                style={{ border: 'none' }}
            >
                <SheetTitle className="sr-only">Posting details</SheetTitle>
                <SheetDescription className="sr-only">
                    Edit the posting's category and comment, and browse similar
                    postings.
                </SheetDescription>

                <div className="relative h-full">
                    {stack.map((id, index) => {
                        const isTop = index === stack.length - 1;

                        return (
                            <div
                                key={id}
                                className={cn(
                                    'absolute inset-y-0 right-0 flex flex-col overflow-hidden bg-background transition-all duration-300',
                                    isTop
                                        ? 'shadow-xl'
                                        : 'border-l shadow-lg',
                                )}
                                style={{
                                    // Each pushed sheet is indented further
                                    // right, so every level below peeks out
                                    // as a strip on the left.
                                    left: index * STACK_OFFSET_PX,
                                    // Under-sheets still render so their left
                                    // strip peeks out; they are inert and dimmed.
                                    pointerEvents: isTop ? 'auto' : 'none',
                                }}
                            >
                                {!isTop && (
                                    <div className="absolute inset-0 z-10 bg-black/20" />
                                )}
                                <SheetLevel
                                    postingId={id}
                                    isTop={isTop}
                                    categoryOptions={categoryOptions}
                                    onOpenPosting={(nextId) =>
                                        setStack((previous) => [
                                            ...previous,
                                            nextId,
                                        ])
                                    }
                                />
                            </div>
                        );
                    })}

                    {/* Clickable strips of the sheets underneath: jump back to
                        that level by popping the stack. */}
                    {stack.slice(0, -1).map((id, index) => (
                        <button
                            key={`strip-${id}`}
                            type="button"
                            aria-label="Back to previous posting"
                            className="absolute inset-y-0 z-20"
                            style={{
                                left: index * STACK_OFFSET_PX,
                                width: STACK_OFFSET_PX,
                            }}
                            onClick={() =>
                                setStack((previous) =>
                                    previous.slice(0, index + 1),
                                )
                            }
                        />
                    ))}
                </div>
            </SheetContent>
        </Sheet>
    );
}
