<?php

namespace App\Http\Controllers\Platform;

use App\Enums\PlatformSettingKey;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SpiirImportController;
use App\Services\Embeddings\EmbeddingGenerator;
use App\Services\Spiir\Importer;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Request $request, PlatformSettings $settings, Importer $importer, EmbeddingGenerator $embeddings): Response
    {
        return Inertia::render('platform/settings/index', [
            'registrationEnabled' => $settings->registrationEnabled(),
            'enableBanking' => [
                'configured' => $settings->enableBankingConfigured(),
                'appId' => $settings->get(PlatformSettingKey::EnableBankingAppId),
                'source' => $this->enableBankingSource($settings),
            ],
            'openAi' => [
                'configured' => $settings->openAiConfigured(),
                'source' => $this->openAiSource($settings),
            ],
            'similarity' => [
                'enabled' => $settings->similarityEnabled(),
                'autoCategorize' => $settings->similarityAutoCategorize(),
                'cost' => $embeddings->costEstimate(),
            ],
            'spiir' => [
                'canImport' => $importer->canImport(),
                'hasDefaultCategories' => $importer->hasDefaultCategories(),
            ],
            'import' => SpiirImportController::sessionImportStatus($request),
        ]);
    }

    /**
     * Whether the Enable Banking credentials come from a stored setting or
     * only from the environment fallback.
     */
    private function enableBankingSource(PlatformSettings $settings): ?string
    {
        if ($settings->isStored(PlatformSettingKey::EnableBankingAppId)
            || $settings->isStored(PlatformSettingKey::EnableBankingPrivateKey)) {
            return 'database';
        }

        return $settings->enableBankingConfigured() ? 'environment' : null;
    }

    /**
     * Whether the OpenAI key comes from a stored setting or only from the
     * environment fallback.
     */
    private function openAiSource(PlatformSettings $settings): ?string
    {
        if ($settings->isStored(PlatformSettingKey::OpenAiApiKey)) {
            return 'database';
        }

        return $settings->openAiConfigured() ? 'environment' : null;
    }
}
