import { Head, useForm } from '@inertiajs/react';
import RegionFields from '@/components/region-fields';
import type { RegionFormData, RegionOptions } from '@/components/region-fields';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { store } from '@/routes/onboarding/region';

type Props = RegionOptions & {
    defaultCountry: string | null;
    defaultTimezone: string | null;
    defaultReportingCurrency: string | null;
    reportingCurrencyLocked: boolean;
};

export default function OnboardingRegion({
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
        <>
            <Head title="Region" />
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
                        reportingCurrencyLocked={reportingCurrencyLocked}
                    />
                </div>

                <Button
                    type="submit"
                    className="w-full"
                    disabled={processing}
                    data-test="continue-button"
                >
                    {processing && <Spinner />}
                    Continue
                </Button>
            </form>
        </>
    );
}

OnboardingRegion.layout = (page: React.ReactNode) => (
    <OnboardingLayout
        title="Your region"
        description="Where do you live? This sets number formatting, your timezone, and the currency your money is reported in."
        step="region"
    >
        {page}
    </OnboardingLayout>
);
