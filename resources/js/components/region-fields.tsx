import { useMemo } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type RegionFormData = {
    country_code: string;
    timezone: string;
    reporting_currency: string;
};

export type RegionOptions = {
    /** ISO country code => { currency, locale } defaults. */
    countries: Record<string, { currency: string; locale: string }>;
    /** ISO country code => IANA timezone identifiers (first is canonical). */
    timezones: Record<string, string[]>;
    /** Currency codes with ECB reference-rate coverage. */
    currencies: string[];
};

const regionNames = new Intl.DisplayNames(['en'], { type: 'region' });
const currencyNames = new Intl.DisplayNames(['en'], { type: 'currency' });

function countryName(code: string): string {
    return regionNames.of(code) ?? code;
}

function currencyLabel(code: string): string {
    const name = currencyNames.of(code);

    return name && name !== code ? `${name} (${code})` : code;
}

/**
 * Country / timezone / reporting-currency fields shared by the onboarding
 * step and the standalone region-setup page. Changing the country applies
 * its CLDR-derived defaults: the canonical timezone and the territory's own
 * currency when the ECB covers it.
 */
export default function RegionFields({
    countries,
    timezones,
    currencies,
    data,
    setData,
    errors,
    reportingCurrencyLocked = false,
}: RegionOptions & {
    data: RegionFormData;
    setData: (key: keyof RegionFormData, value: string) => void;
    errors: Partial<Record<keyof RegionFormData, string>>;
    reportingCurrencyLocked?: boolean;
}) {
    const countryOptions = useMemo(
        () =>
            Object.keys(countries).sort((a, b) =>
                countryName(a).localeCompare(countryName(b)),
            ),
        [countries],
    );

    const currencyOptions = useMemo(
        () =>
            [...currencies].sort((a, b) =>
                currencyLabel(a).localeCompare(currencyLabel(b)),
            ),
        [currencies],
    );

    const timezoneOptions = useMemo(
        () => [...new Set(Object.values(timezones).flat())].sort(),
        [timezones],
    );

    const selectCountry = (value: string) => {
        setData('country_code', value);

        const zones = timezones[value] ?? [];

        if (zones.length > 0 && data.timezone === '') {
            setData('timezone', zones[0]);
        }

        const defaultCurrency = countries[value]?.currency;

        if (defaultCurrency && currencies.includes(defaultCurrency)) {
            setData('reporting_currency', defaultCurrency);
        }
    };

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="country_code">Country</Label>
                <Select
                    value={data.country_code}
                    onValueChange={selectCountry}
                    name="country_code"
                >
                    <SelectTrigger id="country_code" className="w-full">
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
                <InputError message={errors.country_code} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="timezone">Timezone</Label>
                <Select
                    value={data.timezone}
                    onValueChange={(value) => setData('timezone', value)}
                    name="timezone"
                >
                    <SelectTrigger id="timezone" className="w-full">
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
                <InputError message={errors.timezone} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="reporting_currency">Reporting currency</Label>
                <Select
                    value={data.reporting_currency}
                    onValueChange={(value) =>
                        setData('reporting_currency', value)
                    }
                    name="reporting_currency"
                    disabled={reportingCurrencyLocked}
                >
                    <SelectTrigger id="reporting_currency" className="w-full">
                        <SelectValue placeholder="Select currency" />
                    </SelectTrigger>
                    <SelectContent>
                        {currencyOptions.map((code) => (
                            <SelectItem key={code} value={code}>
                                {currencyLabel(code)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {reportingCurrencyLocked ? (
                    <p className="text-sm text-muted-foreground">
                        Set by your Spiir import and locked once monetary
                        history exists.
                    </p>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        All amounts are reported in this currency. It cannot be
                        changed later.
                    </p>
                )}
                <InputError message={errors.reporting_currency} />
            </div>
        </>
    );
}
