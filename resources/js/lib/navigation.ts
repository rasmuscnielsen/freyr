import { BarChart3, ListChecks, Settings, WalletCards } from 'lucide-react';
import { dashboard } from '@/routes';
import { index as bankAccounts } from '@/routes/bank-accounts';
import { index as postings } from '@/routes/postings';
import { edit as profile } from '@/routes/profile';
import type { NavItem } from '@/types';

export const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: BarChart3,
    },
    {
        title: 'Postings',
        href: postings(),
        icon: ListChecks,
    },
    {
        title: 'Bank accounts',
        href: bankAccounts(),
        icon: WalletCards,
    },
];

export const footerNavItems: NavItem[] = [
    {
        title: 'Settings',
        href: profile(),
        icon: Settings,
    },
];
