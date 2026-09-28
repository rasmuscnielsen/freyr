import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import DeleteUser from '@/components/settings/delete-user';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

const regionNames = new Intl.DisplayNames(['en'], { type: 'region' });

function countryName(code: string): string {
    return regionNames.of(code) ?? code;
}

export default function Profile({
    mustVerifyEmail,
    status,
    countries,
    timezones,
    reportingCurrency,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    countries: Record<string, { currency: string; locale: string }>;
    timezones: Record<string, string[]>;
    reportingCurrency: string | null;
}) {
    const { auth } = usePage<PageProps>().props;

    const [countryCode, setCountryCode] = useState(
        auth.user.country_code ?? '',
    );
    const [timezone, setTimezone] = useState(auth.user.timezone ?? '');

    const countryOptions = useMemo(
        () =>
            Object.keys(countries).sort((a, b) =>
                countryName(a).localeCompare(countryName(b)),
            ),
        [countries],
    );

    const timezoneOptions = useMemo(
        () => [...new Set(Object.values(timezones).flat())].sort(),
        [timezones],
    );

    const selectCountry = (value: string) => {
        setCountryCode(value);

        const zones = timezones[value] ?? [];

        if (zones.length > 0 && timezone === '') {
            setTimezone(zones[0]);
        }
    };

    return (
        <>
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile"
                    description="Update your name and email address"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Email address"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Your email address is unverified.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Click here to re-send the
                                                verification email.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                A new verification link has been
                                                sent to your email address.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="grid gap-2">
                                <Label htmlFor="country_code">Country</Label>

                                <Select
                                    name="country_code"
                                    value={countryCode}
                                    onValueChange={selectCountry}
                                >
                                    <SelectTrigger
                                        id="country_code"
                                        className="mt-1 w-full"
                                    >
                                        <SelectValue placeholder="Select country" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {countryOptions.map((code) => (
                                            <SelectItem key={code} value={code}>
                                                {countryName(code)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <InputError
                                    className="mt-2"
                                    message={errors.country_code}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="timezone">Timezone</Label>

                                <Select
                                    name="timezone"
                                    value={timezone}
                                    onValueChange={setTimezone}
                                >
                                    <SelectTrigger
                                        id="timezone"
                                        className="mt-1 w-full"
                                    >
                                        <SelectValue placeholder="Select timezone" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {timezoneOptions.map((zone) => (
                                            <SelectItem key={zone} value={zone}>
                                                {zone.replaceAll('_', ' ')}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <InputError
                                    className="mt-2"
                                    message={errors.timezone}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="reporting_currency">
                                    Reporting currency
                                </Label>

                                <Input
                                    id="reporting_currency"
                                    className="mt-1 block w-full"
                                    value={reportingCurrency ?? ''}
                                    disabled
                                />

                                <p className="text-sm text-muted-foreground">
                                    Set during your region setup and cannot be
                                    changed.
                                </p>
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
