<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\PlatformSettingKey;
use App\Http\Requests\Platform\StoreEnableBankingCredentialsRequest;
use App\Http\Requests\RegionSetupRequest;
use App\Jobs\GeneratePostingEmbeddings;
use App\Models\User;
use App\Services\Spiir\Importer;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
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
            'reportingCurrencyLocked' => $request->user()->hasCompletedRegionSetup()
                || $request->user()->postings()->exists(),
        ]);
    }

    /**
     * The admin's own region preferences — a personal setting even though it
     * is collected inside the platform wizard. Storing them completes the
     * user's region setup and locks the reporting currency.
     */
    public function storeRegion(RegionSetupRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($user->hasCompletedRegionSetup() || $user->postings()->exists()) {
            $data['reporting_currency'] = $user->reporting_currency;
        }

        $user->update([
            ...$data,
            'region_completed_at' => now(),
        ]);

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

    public function spiir(Request $request, Importer $importer): Response
    {
        return Inertia::render('onboarding/spiir', [
            'canImport' => $importer->canImport(),
            'hasDefaultCategories' => $importer->hasDefaultCategories(),
            'import' => SpiirImportController::sessionImportStatus($request),
        ]);
    }

    public function similarity(PlatformSettings $settings): Response
    {
        return Inertia::render('onboarding/similarity', [
            'openAi' => [
                'configured' => $settings->openAiConfigured(),
                'source' => $settings->isStored(PlatformSettingKey::OpenAiApiKey)
                    ? 'database'
                    : ($settings->openAiConfigured() ? 'environment' : null),
            ],
            'enabled' => $settings->similarityEnabled(),
            'since' => $settings->similaritySince(),
            'autoCategorize' => $settings->similarityAutoCategorize(),
        ]);
    }

    /**
     * The final wizard step: optionally store an OpenAI key, pick how far
     * back to analyze postings, then mark onboarding complete either way.
     */
    public function storeSimilarity(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'min:10', 'max:255'],
            'enabled' => ['required', 'boolean'],
            'since' => ['required', 'string', 'in:1y,2y,5y,all'],
            'auto_categorize' => ['required', 'boolean'],
        ]);

        if (filled($data['api_key'] ?? null)) {
            $settings->storeOpenAiApiKey(trim($data['api_key']));
        }

        if ($data['enabled'] && ! $settings->openAiConfigured()) {
            throw ValidationException::withMessages([
                'api_key' => 'An OpenAI API key is required to enable similar postings.',
            ]);
        }

        $settings->set(PlatformSettingKey::SimilarityEnabled, (bool) $data['enabled']);
        $settings->set(PlatformSettingKey::SimilaritySince, $data['since']);
        $settings->set(PlatformSettingKey::SimilarityAutoCategorize, (bool) $data['auto_categorize']);
        $settings->markOnboardingCompleted();

        if ($settings->similarityAvailable()) {
            GeneratePostingEmbeddings::dispatch()->afterCommit();
        }

        return redirect()->route('dashboard')->with('status', 'onboarding-completed');
    }

    public function complete(PlatformSettings $settings): RedirectResponse
    {
        $settings->markOnboardingCompleted();

        return redirect()->route('dashboard')->with('status', 'onboarding-completed');
    }
}
