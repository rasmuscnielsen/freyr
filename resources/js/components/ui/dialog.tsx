import { Sheet } from '@silk-hq/components';
import type * as React from 'react';

import {
    AdaptiveOverlayContent,
    OverlayClose,
    OverlayRoot,
    OverlayTrigger,
} from '@/components/ui/silk-overlays';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';

function Dialog(
    props: Omit<React.ComponentProps<typeof OverlayRoot>, 'children'> & {
        children?: React.ReactNode;
    },
) {
    return <OverlayRoot {...props} />;
}

function DialogTrigger(
    props: React.ComponentProps<typeof OverlayTrigger>,
) {
    return <OverlayTrigger data-slot="dialog-trigger" {...props} />;
}

function DialogClose(props: React.ComponentProps<typeof OverlayClose>) {
    return <OverlayClose data-slot="dialog-close" {...props} />;
}

function DialogContent({
    className,
    contentClassName,
    children,
    ...props
}: Omit<
    React.ComponentProps<typeof AdaptiveOverlayContent>,
    'presentation' | 'variant'
>) {
    const isMobile = useIsMobile();

    return (
        <AdaptiveOverlayContent
            data-slot="dialog-content"
            presentation={isMobile ? 'bottom' : 'center'}
            variant="dialog"
            className={className}
            contentClassName={contentClassName}
            {...props}
        >
            {children}
        </AdaptiveOverlayContent>
    );
}

function DialogHeader({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="dialog-header"
            className={cn('flex flex-col gap-2 text-center sm:text-left', className)}
            {...props}
        />
    );
}

function DialogFooter({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="dialog-footer"
            className={cn(
                'flex flex-col-reverse gap-2 sm:flex-row sm:justify-end',
                className,
            )}
            {...props}
        />
    );
}

function DialogTitle({
    className,
    ...props
}: React.ComponentProps<typeof Sheet.Title>) {
    return (
        <Sheet.Title
            data-slot="dialog-title"
            className={cn('text-lg leading-none font-semibold', className)}
            {...props}
        />
    );
}

function DialogDescription({
    className,
    ...props
}: React.ComponentProps<typeof Sheet.Description>) {
    return (
        <Sheet.Description
            data-slot="dialog-description"
            className={cn('text-muted-foreground text-sm', className)}
            {...props}
        />
    );
}

export {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
};
