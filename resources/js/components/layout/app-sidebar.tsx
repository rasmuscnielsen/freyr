import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    ListChecks,
    Settings,
    SlidersHorizontal,
    Tags,
    WalletCards,
} from 'lucide-react';
import AppLogo from '@/components/layout/app-logo';
import { NavFooter } from '@/components/layout/nav-footer';
import { NavMain } from '@/components/layout/nav-main';
import { NavUser } from '@/components/layout/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as bankAccountsIndex } from '@/routes/bank-accounts';
import { index as categoriesIndex } from '@/routes/platform/categories';
import { edit as platformSettingsEdit } from '@/routes/platform/settings';
import { index as postingsIndex } from '@/routes/postings';
import { edit as profileEdit } from '@/routes/profile';
import type { NavItem } from '@/types';

const accountNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: BarChart3,
    },
    {
        title: 'Postings',
        href: postingsIndex(),
        icon: ListChecks,
    },
    {
        title: 'Bank accounts',
        href: bankAccountsIndex(),
        icon: WalletCards,
    },
];

const platformNavItems: NavItem[] = [
    {
        title: 'Categories',
        href: categoriesIndex(),
        icon: Tags,
    },
    {
        title: 'Settings',
        href: platformSettingsEdit(),
        icon: SlidersHorizontal,
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const { auth } = usePage().props;
    const { url } = usePage();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain label="Account" items={accountNavItems} />
                {auth.isPlatformAdmin && (
                    <NavMain label="Platform" items={platformNavItems} />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            isActive={url.startsWith('/settings')}
                            tooltip={{ children: 'Settings' }}
                        >
                            <Link href={profileEdit()} prefetch>
                                <Settings />
                                <span>Settings</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
