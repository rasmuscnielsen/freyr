<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['name', 'email', 'email_verified_at', 'password', 'is_platform_admin', 'country_code', 'timezone', 'reporting_currency', 'region_completed_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'region_completed_at' => 'datetime',
        ];
    }

    /**
     * Determine whether the user is a platform administrator, flagged via the
     * `is_platform_admin` column — set for the first user by the onboarding
     * wizard.
     */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    /**
     * Region preferences are complete once the personal setup step has run —
     * `region_completed_at` doubles as the reporting-currency lock: ordinary
     * settings edits may still change country and timezone, but never the
     * reporting currency.
     */
    public function hasCompletedRegionSetup(): bool
    {
        return $this->region_completed_at !== null;
    }

    /**
     * The BCP 47 locale used for number formatting, derived from the selected
     * country (e.g. Denmark -> `da-DK`). Falls back to `en-US`.
     */
    public function numberLocale(): string
    {
        return config("regions.countries.{$this->country_code}.locale") ?? 'en-US';
    }

    /**
     * The IANA timezones valid for the selected country — the first entry is
     * the territory's canonical zone.
     *
     * @return list<string>
     */
    public function countryTimezones(): array
    {
        return config("regions.timezones.{$this->country_code}") ?? [];
    }

    /**
     * @return HasMany<BankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * @return HasMany<BankConnection, $this>
     */
    public function bankConnections(): HasMany
    {
        return $this->hasMany(BankConnection::class);
    }

    /**
     * @return HasMany<Posting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class);
    }
}
