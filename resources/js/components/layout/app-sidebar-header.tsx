import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { Breadcrumbs } from '@/components/layout/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const parent =
        breadcrumbs.length > 1 ? breadcrumbs[breadcrumbs.length - 2] : null;
    const current =
        breadcrumbs.length > 0 ? breadcrumbs[breadcrumbs.length - 1] : null;

    return (
        <header className="relative flex h-[calc(4rem+env(safe-area-inset-top,0px))] shrink-0 items-center gap-2 border-b border-border/60 px-6 pt-[env(safe-area-inset-top,0px)] transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1 hidden md:inline-flex" />
                {parent && (
                    <Link
                        href={parent.href}
                        aria-label={`Back to ${parent.title}`}
                        className="-ml-2 flex items-center text-muted-foreground select-none md:hidden"
                    >
                        <ChevronLeft className="size-6" />
                    </Link>
                )}
                {current && (
                    <span className="min-w-0 truncate text-sm font-medium md:hidden">
                        {current.title}
                    </span>
                )}
                <div className="hidden min-w-0 md:flex">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>
        </header>
    );
}
