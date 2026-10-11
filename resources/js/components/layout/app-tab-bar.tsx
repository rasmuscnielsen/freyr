import { Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, Ellipsis, LogOut } from 'lucide-react';
import { useState } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { footerNavItems, mainNavItems } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { edit as profileRoute } from '@/routes/profile';
import type { NavItem } from '@/types';

const tabs: NavItem[] = mainNavItems.slice(0, 4);
const moreItems: NavItem[] = [...mainNavItems.slice(4), ...footerNavItems];

const itemStyles =
    'flex h-[52px] select-none flex-col items-center justify-center gap-0.5 text-[10px] font-medium leading-none';

export function AppTabBar() {
    const { auth } = usePage().props;
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const cleanup = useMobileNavigation();
    const getInitials = useInitials();
    const [moreOpen, setMoreOpen] = useState(false);

    // Settings covers the platform admin pages too, which live under /platform.
    const moreActive =
        moreItems.some((item) => isCurrentOrParentUrl(item.href)) ||
        isCurrentOrParentUrl('/platform');

    return (
        <>
            <nav
                aria-label="Primary"
                className="fixed inset-x-0 bottom-0 z-40 border-t border-sidebar-border/60 bg-background/85 pb-[env(safe-area-inset-bottom,0px)] backdrop-blur-lg md:hidden"
            >
                <div className="grid grid-cols-4">
                    {tabs.map((item) => {
                        const active = isCurrentOrParentUrl(item.href);

                        return (
                            <Link
                                key={item.title}
                                href={item.href}
                                prefetch
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    itemStyles,
                                    active
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {item.icon && (
                                    <item.icon
                                        className="size-6"
                                        strokeWidth={active ? 2.25 : 1.75}
                                    />
                                )}
                                {item.title}
                            </Link>
                        );
                    })}
                    <button
                        type="button"
                        onClick={() => setMoreOpen(true)}
                        className={cn(
                            itemStyles,
                            moreActive
                                ? 'text-foreground'
                                : 'text-muted-foreground',
                        )}
                    >
                        <Ellipsis
                            className="size-6"
                            strokeWidth={moreActive ? 2.25 : 1.75}
                        />
                        More
                    </button>
                </div>
            </nav>

            <Sheet open={moreOpen} onOpenChange={setMoreOpen}>
                <SheetContent
                    side="right"
                    aria-describedby={undefined}
                    contentClassName="gap-0 px-4 pb-4"
                    className="md:hidden"
                >
                    <SheetTitle className="sr-only">More</SheetTitle>
                    {auth.user && (
                        <Link
                            href={profileRoute()}
                            prefetch
                            onClick={() => setMoreOpen(false)}
                            className="mb-4 flex min-h-[64px] items-center gap-3 rounded-2xl bg-muted/60 px-4 py-3"
                        >
                            <Avatar className="size-11 overflow-hidden rounded-full">
                                <AvatarImage
                                    src={auth.user.avatar}
                                    alt={auth.user.name}
                                />
                                <AvatarFallback className="rounded-full bg-neutral-200 text-base text-black dark:bg-neutral-700 dark:text-white">
                                    {getInitials(auth.user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <div className="grid min-w-0 flex-1 text-left">
                                <span className="truncate font-medium">
                                    {auth.user.name}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {auth.user.email}
                                </span>
                            </div>
                            <ChevronRight className="size-5 shrink-0 text-muted-foreground" />
                        </Link>
                    )}
                    <nav className="flex flex-col">
                        <div className="divide-y divide-border/60 overflow-hidden rounded-2xl bg-muted/60">
                            {moreItems.map((item) => (
                                <Link
                                    key={item.title}
                                    href={item.href}
                                    prefetch
                                    onClick={() => setMoreOpen(false)}
                                    className="flex min-h-[52px] items-center gap-3 px-4 text-[15px] font-medium text-foreground"
                                >
                                    {item.icon && (
                                        <item.icon className="size-5 opacity-80" />
                                    )}
                                    <span className="flex-1">{item.title}</span>
                                    <ChevronRight className="size-4 text-muted-foreground" />
                                </Link>
                            ))}
                        </div>
                        <div className="mt-3 overflow-hidden rounded-2xl bg-muted/60">
                            <Link
                                href={logout()}
                                as="button"
                                onClick={() => {
                                    cleanup();
                                    router.flushAll();
                                }}
                                className="flex min-h-[52px] w-full items-center gap-3 px-4 text-left text-[15px] font-medium text-destructive"
                            >
                                <LogOut className="size-5" />
                                Log out
                            </Link>
                        </div>
                    </nav>
                </SheetContent>
            </Sheet>
        </>
    );
}
