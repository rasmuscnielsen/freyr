<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\PlatformSettingKey;
use App\Finance\SpiirExportImporter;
use App\Http\Requests\Platform\StoreEnableBankingCredentialsRequest;
use App\Http\Requests\RegionSetupRequest;
use App\Models\User;
use App\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function welcome(): Response
    {
        return Inertia::render('onboarding/welcome', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('onboarding.region');
    }

    public function region(Request $request): Response
    {
        return Inertia::render('onboarding/region', [
            ...RegionSetupController::regionOptions(),
            'defaultCountry' => $request->user()->country_code,
            'defaultTimezone' => $request->user()->timezone,
            'defaultReportingCurrency' => $request->user()->reporting_currency,
        ]);
    }

    /**
     * The admin's own region preferences — a personal setting even though it
     * is collected inside the platform wizard. Storing them completes the
     * user's region setup and locks the reporting currency.
     */
    public function storeRegion(RegionSetupRequest $request): RedirectResponse
    {
        $request->user()->update([
            ...$request->validated(),
            'region_completed_at' => now(),
        ]);

        return redirect()->route('onboarding.registration');
    }

    public function registration(PlatformSettings $settings): Response
    {
        return Inertia::render('onboarding/registration', [
            'registrationEnabled' => $settings->registrationEnabled(),
        ]);
    }

    public function storeRegistration(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $settings->set(PlatformSettingKey::RegistrationEnabled, (bool) $data['enabled']);

        return redirect()->route('onboarding.enable-banking');
    }

    public function enableBanking(PlatformSettings $settings): Response
    {
        return Inertia::render('onboarding/enable-banking', [
            'configured' => $settings->enableBankingConfigured(),
            'appId' => $settings->get(PlatformSettingKey::EnableBankingAppId),
        ]);
    }

    public function storeEnableBanking(StoreEnableBankingCredentialsRequest $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validated();

        $settings->storeEnableBankingCredentials($data['app_id'], $request->privateKeyContents());

        return redirect()->route('onboarding.spiir');
    }

    public function skipEnableBanking(): RedirectResponse
    {
        return redirect()->route('onboarding.spiir');
    }

    public function spiir(Request $request, SpiirExportImporter $importer): Response
    {
        return Inertia::render('onboarding/spiir', [
            'canImport' => $importer->canImport(),
            'hasDefaultCategories' => $importer->hasDefaultCategories(),
            'import' => SpiirImportController::sessionImportStatus($request),
        ]);
    }

    public function complete(PlatformSettings $settings): RedirectResponse
    {
        $settings->markOnboardingCompleted();

        return redirect()->route('dashboard')->with('status', 'onboarding-completed');
    }
}
