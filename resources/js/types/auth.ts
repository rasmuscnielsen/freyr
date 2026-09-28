export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    country_code: string | null;
    timezone: string | null;
    reporting_currency: string | null;
    region_completed_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/**
 * The signed-in user's region preferences, shared on every page for number
 * formatting, timezone display, and reporting-currency labels. Null for
 * guests.
 */
export type Region = {
    country: string | null;
    timezone: string | null;
    reportingCurrency: string | null;
    locale: string;
    setupCompleted: boolean;
};

export type Auth = {
    user: User;
    isPlatformAdmin: boolean;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
