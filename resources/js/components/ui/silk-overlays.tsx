import { Scroll, Sheet } from '@silk-hq/components';
import { XIcon } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Shared internals for `ui/dialog.tsx` and `ui/sheet.tsx`.
 *
 * Every overlay in the app is a Silk sheet whose presentation adapts to the
 * platform: bottom sheets with swipe-to-dismiss on mobile (iOS-style), and
 * centered modals or edge slide-overs on desktop.
 *
 * `license` is required by @silk-hq/components — 'non-commercial' unless a
 * commercial Silk license is purchased (see https://silkhq.com/terms).
 */
const SILK_LICENSE = 'non-commercial';

export type OverlayPresentation =
    | 'center'
    | 'bottom'
    | 'top'
    | 'left'
    | 'right';

const TRACK_BY_PRESENTATION: Record<
    OverlayPresentation,
    'top' | 'bottom' | 'left' | 'right'
> = {
    center: 'top',
    bottom: 'bottom',
    top: 'top',
    left: 'left',
    right: 'right',
};

/**
 * Shares the sheet's active detent between `Sheet.Root` (which owns the
 * state) and `Sheet.Content` (which gates the inner scroll and the
 * handle's step/dismiss action on it). Detent indexes with one
 * intermediate detent: 0 = outside, 1 = ~2/3 rest, 2 = fully expanded.
 */
const OverlayDetentContext = React.createContext<{
    detent: number;
} | null>(null);

/**
 * The hosting sheet's active detent index (1 when the sheet has none).
 * Sheets with their own View/Content — like the stacked posting sheet —
 * use it to gate their inner scroll and flip the handle between
 * step/dismiss exactly like `AdaptiveOverlayContent` does.
 */
export function useOverlayDetent(): number {
    const { detent } = React.useContext(OverlayDetentContext) ?? {
        detent: 1,
    };
    return detent;
}

export function OverlayRoot({
    open,
    defaultOpen,
    onOpenChange,
    children,
    ...props
}: Omit<
    React.ComponentProps<typeof Sheet.Root>,
    | 'license'
    | 'presented'
    | 'defaultPresented'
    | 'onPresentedChange'
    | 'children'
> & {
    open?: boolean;
    defaultOpen?: boolean;
    onOpenChange?: (open: boolean) => void;
    children?: React.ReactNode;
}) {
    // Mirrors the sheet's active detent so content can gate the inner
    // scroll and flip the handle between step/dismiss. Uncontrolled —
    // the sheet owns the value; we only observe it.
    const [detent, setDetent] = React.useState(1);

    return (
        <Sheet.Root
            license={SILK_LICENSE}
            sheetRole="dialog"
            presented={open}
            defaultPresented={defaultOpen}
            onPresentedChange={onOpenChange}
            defaultActiveDetent={1}
            onActiveDetentChange={setDetent}
            {...props}
        >
            <OverlayDetentContext.Provider value={{ detent }}>
                {children}
            </OverlayDetentContext.Provider>
        </Sheet.Root>
    );
}

export function OverlayTrigger(
    props: React.ComponentProps<typeof Sheet.Trigger>,
) {
    return <Sheet.Trigger action="present" {...props} />;
}

export function OverlayClose(
    props: React.ComponentProps<typeof Sheet.Trigger>,
) {
    return <Sheet.Trigger action="dismiss" {...props} />;
}

export function AdaptiveOverlayContent({
    presentation,
    variant,
    className,
    contentClassName,
    header,
    children,
    ...props
}: React.ComponentProps<typeof Sheet.Content> & {
    presentation: OverlayPresentation;
    /** 'dialog' pads its scroll body like a form; 'sheet' keeps the looser slide-over layout. */
    variant: 'dialog' | 'sheet';
    /** Classes applied to the scrolling body inside the sheet. */
    contentClassName?: string;
    /**
     * Content pinned above the scroll area on bottom sheets (like the
     * iOS share sheet's fixed header) — a grab zone that drags the
     * sheet between detents. On other presentations it renders at the
     * top of the scroll body instead.
     */
    header?: React.ReactNode;
}) {
    const isBottom = presentation === 'bottom';
    const isCenter = presentation === 'center';
    const isEdge = !isBottom && !isCenter;
    const isRight = presentation === 'right';
    const { detent } = React.useContext(OverlayDetentContext) ?? {
        detent: 1,
    };
    const atLastDetent = detent >= 2;

    return (
        <Sheet.Portal>
            <Sheet.View
                contentPlacement={presentation}
                tracks={TRACK_BY_PRESENTATION[presentation]}
                // Mobile bottom sheets rest at ~2/3 height first and can be
                // pushed up to fill the screen, like the iOS share sheet.
                detents={isBottom ? '66vh' : undefined}
                nativeEdgeSwipePrevention={isBottom}
                // Centered dialogs appear in place with a gentle backdrop
                // fade instead of sliding in across the viewport.
                enteringAnimationSettings={
                    isCenter
                        ? { preset: 'gentle', contentMove: false }
                        : undefined
                }
                exitingAnimationSettings={
                    isCenter
                        ? { preset: 'gentle', contentMove: false }
                        : undefined
                }
                swipeOvershoot={isBottom ? false : undefined}
                className="z-50"
            >
                <Sheet.Backdrop
                    themeColorDimming="auto"
                    travelAnimation={{ opacity: [0, 0.5] }}
                />
                <Sheet.Content
                    className={cn(
                        'flex flex-col',
                        !isRight && 'bg-background shadow-xl',
                        isBottom &&
                            // Bottom sheets size to ~90vh so the "fully
                            // expanded" detent (= measured content height)
                            // sits above the 66vh intermediate detent —
                            // the sheet rests at ~2/3 and pushes up to
                            // near-full, like the iOS share sheet.
                            'h-[90dvh] w-full rounded-t-2xl border-t bg-background pb-[env(safe-area-inset-bottom,0px)] shadow-xl',
                        presentation === 'right' &&
                            // Silk zeroes content margins and paints the
                            // content white, so the floating slide-over
                            // look comes from an inner card with real
                            // margins (see below) over a transparent shell.
                            'h-full w-3/4 bg-transparent shadow-none sm:max-w-sm',
                        presentation === 'left' &&
                            'h-full w-3/4 border-r bg-background shadow-xl sm:max-w-xs',
                        presentation === 'top' &&
                            'max-h-[85dvh] w-full border-b bg-background shadow-xl',
                        // Silk sizes center-placed content to a fixed
                        // fraction of the view; h-auto restores the
                        // shrink-to-fit sizing dialogs should have.
                        isCenter &&
                            'h-auto max-h-[85dvh] w-full max-w-[calc(100%-2rem)] rounded-lg border bg-background shadow-xl sm:max-w-lg',
                        className,
                    )}
                    travelAnimation={
                        isCenter
                            ? { opacity: [0, 1], scale: [0.95, 1] }
                            : undefined
                    }
                    {...props}
                >
                    {(() => {
                        const body = (
                            <>
                                {isBottom && (
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
                                        {header}
                                    </>
                                )}
                                <Scroll.Root asChild>
                                    <Scroll.View
                                        className="min-h-0 flex-1"
                                        // The scroll must not eat gestures
                                        // while the sheet sits at the
                                        // intermediate detent — upward
                                        // drags expand the sheet first
                                        // (iOS share-sheet arbitration).
                                        scrollGesture={
                                            isBottom && !atLastDetent
                                                ? false
                                                : 'auto'
                                        }
                                        scrollGestureTrap={
                                            isBottom
                                                ? { yEnd: true }
                                                : undefined
                                        }
                                        onScrollStart={{
                                            dismissKeyboard: true,
                                        }}
                                    >
                                        <Scroll.Content
                                            className={cn(
                                                variant === 'dialog'
                                                    ? 'grid gap-4 p-6'
                                                    : 'flex flex-col gap-4',
                                                isEdge && 'min-h-full',
                                                contentClassName,
                                            )}
                                        >
                                            {!isBottom && header}
                                            {children}
                                        </Scroll.Content>
                                    </Scroll.View>
                                </Scroll.Root>
                                {!isBottom && presentation !== 'left' && (
                                    <Sheet.Trigger
                                        action="dismiss"
                                        className="ring-offset-background focus:ring-ring absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
                                    >
                                        <XIcon />
                                        <span className="sr-only">
                                            Close
                                        </span>
                                    </Sheet.Trigger>
                                )}
                            </>
                        );

                        // The desktop slide-over floats off the viewport
                        // edges like Silk's own demos: an inner card with
                        // real margins (Silk zeroes margins on Content),
                        // while Content stays a transparent full-size
                        // shell so the backdrop covers the whole page.
                        return isRight ? (
                            <div className="relative m-3 flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border bg-background shadow-xl">
                                {body}
                            </div>
                        ) : (
                            body
                        );
                    })()}
                </Sheet.Content>
            </Sheet.View>
        </Sheet.Portal>
    );
}
