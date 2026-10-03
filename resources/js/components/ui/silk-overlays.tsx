import { Scroll, Sheet } from '@silk-hq/components';
import { XIcon } from 'lucide-react';
import type * as React from 'react';

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
    return (
        <Sheet.Root
            license={SILK_LICENSE}
            sheetRole="dialog"
            presented={open}
            defaultPresented={defaultOpen}
            onPresentedChange={onOpenChange}
            {...props}
        >
            {children}
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
    children,
    ...props
}: React.ComponentProps<typeof Sheet.Content> & {
    presentation: OverlayPresentation;
    /** 'dialog' pads its scroll body like a form; 'sheet' keeps the looser slide-over layout. */
    variant: 'dialog' | 'sheet';
    /** Classes applied to the scrolling body inside the sheet. */
    contentClassName?: string;
}) {
    const isBottom = presentation === 'bottom';
    const isCenter = presentation === 'center';
    const isEdge = !isBottom && !isCenter;

    return (
        <Sheet.Portal>
            <Sheet.View
                contentPlacement={presentation}
                tracks={TRACK_BY_PRESENTATION[presentation]}
                swipeOvershoot={isBottom}
                className="z-50"
                style={
                    isCenter
                        ? undefined
                        : {
                              height: 'calc(var(--silk-100-lvh-dvh-pct) + 60px)',
                          }
                }
            >
                <Sheet.Backdrop
                    themeColorDimming="auto"
                    travelAnimation={{ opacity: [0, 0.5] }}
                />
                <Sheet.Content
                    className={cn(
                        'flex flex-col bg-background shadow-xl',
                        isBottom &&
                            'max-h-[92dvh] w-full rounded-t-2xl border-t pb-[env(safe-area-inset-bottom,0px)]',
                        presentation === 'right' &&
                            'h-full w-3/4 border-l sm:max-w-sm',
                        presentation === 'left' &&
                            'h-full w-3/4 border-r sm:max-w-xs',
                        presentation === 'top' &&
                            'max-h-[85dvh] w-full border-b',
                        isCenter &&
                            'max-h-[85dvh] w-full max-w-[calc(100%-2rem)] rounded-lg border sm:max-w-lg',
                        className,
                    )}
                    travelAnimation={
                        isCenter
                            ? { opacity: [0, 1], scale: [0.95, 1] }
                            : undefined
                    }
                    {...props}
                >
                    {isBottom && (
                        <>
                            <Sheet.BleedingBackground className="bg-background" />
                            <Sheet.Handle
                                action="dismiss"
                                aria-label="Close"
                                className="mx-auto mt-2 mb-1 h-[5px] w-9 shrink-0 rounded-full border-0 bg-muted-foreground/30"
                            />
                        </>
                    )}
                    <Scroll.Root asChild>
                        <Scroll.View
                            className="min-h-0 flex-1"
                            onScrollStart={{ dismissKeyboard: true }}
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
                            <span className="sr-only">Close</span>
                        </Sheet.Trigger>
                    )}
                </Sheet.Content>
            </Sheet.View>
        </Sheet.Portal>
    );
}
