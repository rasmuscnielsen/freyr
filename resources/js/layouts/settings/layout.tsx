import { Link, router, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { index as categoriesIndex } from '@/routes/platform/categories';
import { edit as platformSettingsEdit } from '@/routes/platform/settings';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

type SettingsNavSection = {
    label: string;
    items: NavItem[];
};

const accountNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
];

const platformNavItems: NavItem[] = [
    {
        title: 'General',
        href: platformSettingsEdit(),
        icon: null,
    },
    {
        title: 'Categories',
        href: categoriesIndex(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { auth } = usePage().props;

    const sections: SettingsNavSection[] = [
        { label: 'Account', items: accountNavItems },
        ...(auth.isPlatformAdmin
            ? [{ label: 'Platform', items: platformNavItems }]
            : []),
    ];

    const currentItem =
        sections
            .flatMap((section) => section.items)
            .find((item) => isCurrentOrParentUrl(item.href)) ??
        accountNavItems[0];

    return (
        <div className="px-4 py-6">
            <Heading
                title="Settings"
                description="Manage your account and platform settings"
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <div className="w-full max-w-xl lg:hidden">
                    <Select
                        value={toUrl(currentItem.href)}
                        onValueChange={(url) => router.visit(url)}
                    >
                        <SelectTrigger
                            className="w-full"
                            aria-label="Settings pages"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {sections.map((section) => (
                                <SelectGroup key={section.label}>
                                    <SelectLabel>{section.label}</SelectLabel>
                                    {section.items.map((item, index) => (
                                        <SelectItem
                                            key={`${toUrl(item.href)}-${index}`}
                                            value={toUrl(item.href)}
                                        >
                                            {item.title}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <aside className="hidden lg:block lg:w-48">
                    <nav
                        className="flex flex-col space-y-6"
                        aria-label="Settings"
                    >
                        {sections.map((section) => (
                            <div key={section.label} className="space-y-1">
                                <p className="px-3 pb-1 text-xs font-medium tracking-wide text-muted-foreground">
                                    {section.label}
                                </p>
                                {section.items.map((item, index) => (
                                    <Button
                                        key={`${toUrl(item.href)}-${index}`}
                                        size="sm"
                                        variant="ghost"
                                        asChild
                                        className={cn('w-full justify-start', {
                                            'bg-muted': isCurrentOrParentUrl(
                                                item.href,
                                            ),
                                        })}
                                    >
                                        <Link href={item.href}>
                                            {item.icon && (
                                                <item.icon className="h-4 w-4" />
                                            )}
                                            {item.title}
                                        </Link>
                                    </Button>
                                ))}
                            </div>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="w-full space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
