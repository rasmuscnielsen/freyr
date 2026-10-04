import { useForm } from '@inertiajs/react';
import { Scroll, Sheet, SheetStack } from '@silk-hq/components';
import type { SheetContentProps } from '@silk-hq/components';
import { ChevronsUpDown, Sparkles, StarIcon, XIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { CategoryCombobox } from '@/components/postings/category-combobox';
import type { CategoryComboboxOption } from '@/components/postings/category-combobox';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { OverlayRoot, useOverlayDetent } from '@/components/ui/silk-overlays';
import { Spinner } from '@/components/ui/spinner';
import { useIsMobile } from '@/hooks/use-mobile';
import { formatDate, formatDayMonth } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { useRegion } from '@/lib/region';
import { cn } from '@/lib/utils';
import {
    similar as postingsSimilar,
    update as updatePosting,
} from '@/routes/postings';

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

type StackEntry = { postingId: number; presented: boolean };

/**
 * The receding effect a covered sheet plays as deeper levels stack on top,
 * matching Silk's "Sheet with Stacking" recipe: scale down and shift away
 * from the incoming edge so the sheet below keeps peeking out.
 */
function stackingAnimation(
    presentation: 'bottom' | 'right',
): SheetContentProps['stackingAnimation'] {
    const translate = ({ progress }: { progress: number }) =>
        progress <= 1
            ? `${progress * -10}px`
            : `calc(-12.5px + 2.5px * ${progress})`;

    return presentation === 'right'
        ? {
              translateX: translate,
              scale: [1, 0.933],
              transformOrigin: '0 50%',
          }
        : {
              translateY: translate,
              scale: [1, 0.933],
              transformOrigin: '50% 0',
          };
}

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
    const isMobile = useIsMobile();
    const atLastDetent = useOverlayDetent() >= 2;
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
        // Percentage heights don't resolve against Silk's measured content
        // box, so mobile pins a tall detent while desktop fills the edge
        // slide-over's explicit height.
        <div className="flex h-[85dvh] min-h-0 flex-col md:h-full">
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
                <Scroll.Root asChild>
                    <Scroll.View
                        className="min-h-0 flex-1"
                        // While the sheet rests at its intermediate detent,
                        // upward drags expand it instead of scrolling — the
                        // scroll only takes over once fully expanded (iOS
                        // share-sheet arbitration).
                        scrollGesture={
                            isMobile && !atLastDetent ? false : 'auto'
                        }
                        scrollGestureTrap={
                            isMobile ? { yEnd: true } : undefined
                        }
                        onScrollStart={{ dismissKeyboard: true }}
                    >
                        <Scroll.Content className="grid gap-5 p-4">
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
                                        onClick={() =>
                                            setIsPickingCategory(true)
                                        }
                                    >
                                        {selectedCategoryLabel ??
                                            'Uncategorized'}
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
                                    message={
                                        form.errors.categorized_subcategory_id
                                    }
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
                                        form.setData(
                                            'comment',
                                            event.target.value,
                                        )
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
                                        Kept out of the overview so one-off
                                        amounts don't skew your numbers.
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
                                                            match.similarity *
                                                                100,
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
                        </Scroll.Content>
                    </Scroll.View>
                </Scroll.Root>
            )}
        </div>
    );
}

/**
 * One real Silk sheet per stack level. Sheets nest logically inside each
 * other's content — as a deeper level presents, the level below recedes with
 * the stacking animation and is dimmed by the deeper sheet's backdrop.
 */
function StackedSheetLevel({
    levels,
    index,
    onPush,
    onDismissLevel,
    onExited,
    categoryOptions,
}: {
    levels: StackEntry[];
    index: number;
    onPush: (postingId: number) => void;
    onDismissLevel: (index: number) => void;
    onExited: (index: number, entry: StackEntry) => void;
    categoryOptions: CategoryComboboxOption[];
}) {
    const isMobile = useIsMobile();
    const atLastDetent = useOverlayDetent() >= 2;
    const entry = levels[index];
    const presentation = isMobile ? 'bottom' : 'right';
    const next = index + 1 < levels.length ? index + 1 : null;
    const isTop =
        index === levels.map((level) => level.presented).lastIndexOf(true);

    if (entry === undefined) {
        return null;
    }

    return (
        <OverlayRoot
            forComponent="closest"
            open={entry.presented}
            onOpenChange={(open) => {
                if (!open) {
                    onDismissLevel(index);
                }
            }}
        >
            <Sheet.Portal>
                <Sheet.View
                    contentPlacement={presentation}
                    tracks={presentation}
                    // Mobile levels rest at ~2/3 height and can be pushed up
                    // to fill the screen, like the iOS share sheet.
                    detents={isMobile ? '66vh' : undefined}
                    swipeOvershoot={isMobile ? false : undefined}
                    nativeEdgeSwipePrevention={true}
                    className="z-50"
                    onTravelStatusChange={(status) => {
                        if (status === 'idleOutside' && !entry.presented) {
                            onExited(index, entry);
                        }
                    }}
                >
                    <Sheet.Backdrop
                        themeColorDimming="auto"
                        travelAnimation={{
                            opacity: [0, index === 0 ? 0.5 : 0.25],
                        }}
                    />
                    <Sheet.Content
                        className={cn(
                            'flex flex-col',
                            isMobile
                                ? // ~90vh keeps the measured content height (the
                                  // last detent) above the 66vh intermediate —
                                  // the level rests at ~2/3 and pushes to near-full.
                                  'h-[90dvh] w-full rounded-t-2xl border-t bg-background pb-[env(safe-area-inset-bottom,0px)] shadow-xl'
                                : // Silk zeroes content margins and paints the
                                  // content white, so the floating slide-over
                                  // comes from an inner card over a transparent
                                  // shell and the backdrop covers the page.
                                  'h-full w-full bg-transparent shadow-none sm:max-w-xl',
                        )}
                        stackingAnimation={stackingAnimation(presentation)}
                    >
                        {(() => {
                            const body = (
                                <>
                                    {isMobile && (
                                        <>
                                            <Sheet.BleedingBackground className="bg-background" />
                                            <Sheet.Handle
                                                action={
                                                    atLastDetent
                                                        ? 'dismiss'
                                                        : 'step'
                                                }
                                                aria-label={
                                                    atLastDetent
                                                        ? 'Close'
                                                        : 'Expand'
                                                }
                                                className="mx-auto mt-2 mb-1 h-[5px] w-9 shrink-0 rounded-full border-0 bg-muted-foreground/30"
                                            />
                                        </>
                                    )}
                                    <Sheet.Title className="sr-only">
                                        Posting details
                                    </Sheet.Title>
                                    <Sheet.Description className="sr-only">
                                        Edit the posting's category and comment,
                                        and browse similar postings.
                                    </Sheet.Description>

                                    <SheetLevel
                                        postingId={entry.postingId}
                                        isTop={isTop}
                                        categoryOptions={categoryOptions}
                                        onOpenPosting={onPush}
                                    />

                                    {/* The next level nests inside this sheet's content so
                                        Silk tracks it as stacked above in the SheetStack. */}
                                    {next !== null && (
                                        <StackedSheetLevel
                                            key={levels[next].postingId}
                                            levels={levels}
                                            index={next}
                                            onPush={onPush}
                                            onDismissLevel={onDismissLevel}
                                            onExited={onExited}
                                            categoryOptions={categoryOptions}
                                        />
                                    )}

                                    <Sheet.Trigger
                                        action="dismiss"
                                        className="absolute top-4 right-4 rounded-xs opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
                                    >
                                        <XIcon />
                                        <span className="sr-only">Close</span>
                                    </Sheet.Trigger>
                                </>
                            );

                            return isMobile ? (
                                body
                            ) : (
                                <div className="relative m-3 flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border bg-background shadow-xl">
                                    {body}
                                </div>
                            );
                        })()}
                    </Sheet.Content>
                </Sheet.View>
            </Sheet.Portal>
        </OverlayRoot>
    );
}

/**
 * Posting detail sheet with real sheet stacking: clicking a similar posting
 * presents a new sheet on top while the covered level recedes and dims.
 * Dismissing a level (X, swipe, Escape, backdrop) pops just that level.
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
    const [levels, setLevels] = useState<StackEntry[]>([]);
    const [openId, setOpenId] = useState<number | null>(null);

    // Reset the stack when a different posting is opened (render-phase
    // adjustment — React's sanctioned alternative to syncing via an effect).
    if (postingId !== openId) {
        setOpenId(postingId);
        setLevels((previous) =>
            postingId === null
                ? previous.map((entry) => ({ ...entry, presented: false }))
                : [{ postingId, presented: true }],
        );
    }

    // Levels stay mounted while they animate out; each reports idleOutside
    // once gone and is then removed.
    return (
        <SheetStack.Root>
            {levels.length > 0 && (
                <StackedSheetLevel
                    levels={levels}
                    index={0}
                    onPush={(nextId) =>
                        setLevels((previous) => [
                            ...previous.slice(
                                0,
                                previous
                                    .map((entry) => entry.presented)
                                    .lastIndexOf(true) + 1,
                            ),
                            {
                                postingId: nextId,
                                presented: true,
                            },
                        ])
                    }
                    onDismissLevel={(index) => {
                        if (index === 0) {
                            onClose();

                            return;
                        }

                        setLevels((previous) =>
                            previous.map((entry, i) =>
                                i >= index
                                    ? { ...entry, presented: false }
                                    : entry,
                            ),
                        );
                    }}
                    onExited={(index, exitedEntry) =>
                        setLevels((previous) =>
                            previous[index] === exitedEntry
                                ? previous.slice(0, index)
                                : previous,
                        )
                    }
                    categoryOptions={categoryOptions}
                />
            )}
        </SheetStack.Root>
    );
}
