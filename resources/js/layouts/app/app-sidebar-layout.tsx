import { AppContent } from '@/components/layout/app-content';
import { AppShell } from '@/components/layout/app-shell';
import { AppSidebar } from '@/components/layout/app-sidebar';
import { AppSidebarHeader } from '@/components/layout/app-sidebar-header';
import { AppTabBar } from '@/components/layout/app-tab-bar';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
                {/* Keeps page content clear of the fixed mobile tab bar */}
                <div
                    aria-hidden="true"
                    className="h-[calc(4rem+env(safe-area-inset-bottom,0px))] shrink-0 md:hidden"
                />
            </AppContent>
            <AppTabBar />
        </AppShell>
    );
}
