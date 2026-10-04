import { Sheet } from '@silk-hq/components';
import type * as React from 'react';

import {
    AdaptiveOverlayContent,
    OverlayClose,
    type OverlayPresentation,
    OverlayRoot,
    OverlayTrigger,
} from '@/components/ui/silk-overlays';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';

function SheetRoot(
    props: Omit<React.ComponentProps<typeof OverlayRoot>, 'children'> & {
        children?: React.ReactNode;
    },
) {
    return <OverlayRoot {...props} />;
}

function SheetTrigger(props: React.ComponentProps<typeof OverlayTrigger>) {
    return <OverlayTrigger data-slot="sheet-trigger" {...props} />;
}

function SheetClose(props: React.ComponentProps<typeof OverlayClose>) {
    return <OverlayClose data-slot="sheet-close" {...props} />;
}

function SheetContent({
    className,
    contentClassName,
    children,
    side = 'right',
    ...props
}: Omit<
    React.ComponentProps<typeof AdaptiveOverlayContent>,
    'presentation' | 'variant'
> & {
    side?: 'top' | 'right' | 'bottom' | 'left';
}) {
    const isMobile = useIsMobile();

    // On mobile, trailing-edge slideovers become bottom sheets for native
    // iOS-style swipe-to-dismiss; nav drawers stay anchored to their edge.
    const presentation: OverlayPresentation =
        isMobile && side === 'right' ? 'bottom' : side;

    return (
        <AdaptiveOverlayContent
            data-slot="sheet-content"
            presentation={presentation}
            variant="sheet"
            className={className}
            contentClassName={contentClassName}
            {...props}
        >
            {children}
        </AdaptiveOverlayContent>
    );
}

function SheetHeader({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="sheet-header"
            className={cn('flex flex-col gap-1.5 p-4', className)}
            {...props}
        />
    );
}

function SheetFooter({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="sheet-footer"
            className={cn('mt-auto flex flex-col gap-2 p-4', className)}
            {...props}
        />
    );
}

function SheetTitle({
    className,
    ...props
}: React.ComponentProps<typeof Sheet.Title>) {
    return (
        <Sheet.Title
            data-slot="sheet-title"
            className={cn('text-foreground font-semibold', className)}
            {...props}
        />
    );
}

function SheetDescription({
    className,
    ...props
}: React.ComponentProps<typeof Sheet.Description>) {
    return (
        <Sheet.Description
            data-slot="sheet-description"
            className={cn('text-muted-foreground text-sm', className)}
            {...props}
        />
    );
}

export {
    SheetRoot as Sheet,
    SheetTrigger,
    SheetClose,
    SheetContent,
    SheetHeader,
    SheetFooter,
    SheetTitle,
    SheetDescription,
};
