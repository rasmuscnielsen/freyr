import { Head, useForm } from '@inertiajs/react';
import AppLogoIcon from '@/components/layout/app-logo-icon';
import RegionFields from '@/components/region-fields';
import type { RegionFormData, RegionOptions } from '@/components/region-fields';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/region-setup';

type Props = RegionOptions & {
    defaultCountry: string | null;
    defaultTimezone: string | null;
    defaultReportingCurrency: string | null;
    reportingCurrencyLocked: boolean;
};

export default function RegionSetup({
    countries,
    timezones,
    currencies,
    defaultCountry,
    defaultTimezone,
    defaultReportingCurrency,
    reportingCurrencyLocked,
}: Props) {
    const { data, setData, post, processing, errors } = useForm<RegionFormData>(
        {
            country_code: defaultCountry ?? '',
            timezone: defaultTimezone ?? '',
            reporting_currency: defaultReportingCurrency ?? '',
        },
    );

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <Head title="Set up your region" />
            <div className="w-full max-w-md">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <div className="mb-1 flex size-10 items-center justify-center rounded-lg bg-teal-300">
                            <AppLogoIcon className="size-6 fill-current text-teal-900" />
                        </div>
                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">
                                Set up your region
                            </h1>
                            <p className="text-center text-sm text-muted-foreground">
                                Choose your country, timezone, and the currency
                                your money is reported in before you continue.
                            </p>
                        </div>
                    </div>

                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            post(store.url());
                        }}
                        className="flex flex-col gap-6"
                    >
                        <div className="grid gap-6">
                            <RegionFields
                                countries={countries}
                                timezones={timezones}
                                currencies={currencies}
                                data={data}
                                setData={setData}
                                errors={errors}
                                reportingCurrencyLocked={
                                    reportingCurrencyLocked
                                }
                            />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Continue
                        </Button>
                    </form>
                </div>
            </div>
        </div>
    );
}
