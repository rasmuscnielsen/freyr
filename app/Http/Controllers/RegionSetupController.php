<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegionSetupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The personal region-setup step required of every user after the first
 * administrator (who picks these preferences inside the onboarding wizard).
 * Submitting it completes the setup and locks the reporting currency.
 */
class RegionSetupController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('region-setup', [
            ...self::regionOptions(),
            'defaultCountry' => $request->user()->country_code,
            'defaultTimezone' => $request->user()->timezone,
            'defaultReportingCurrency' => $request->user()->reporting_currency,
            // Once monetary history exists the currency is locked even when
            // the setup step was skipped (e.g. a Spiir import set it first).
            'reportingCurrencyLocked' => $request->user()->reporting_currency !== null
                && $request->user()->postings()->exists(),
        ]);
    }

    public function store(RegionSetupRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // A locked reporting currency (set by a Spiir import before setup ran)
        // is not re-opened by completing this form.
        if ($user->reporting_currency !== null && $user->postings()->exists()) {
            $data['reporting_currency'] = $user->reporting_currency;
        }

        $user->update([
            ...$data,
            'region_completed_at' => now(),
        ]);

        return redirect()->route('dashboard');
    }

    /**
     * The picker options shared by the onboarding step and the standalone
     * setup page.
     *
     * @return array<string, mixed>
     */
    public static function regionOptions(): array
    {
        return [
            'countries' => config('regions.countries'),
            'timezones' => config('regions.timezones'),
            'currencies' => config('regions.ecb_currencies'),
        ];
    }
}
