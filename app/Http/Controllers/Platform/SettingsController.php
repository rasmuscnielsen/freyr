<?php

namespace App\Http\Controllers\Platform;

use App\Enums\PlatformSettingKey;
use App\Finance\SpiirExportImporter;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SpiirImportController;
use App\Platform\PlatformSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Request $request, PlatformSettings $settings, SpiirExportImporter $importer): Response
    {
        return Inertia::render('platform/settings/index', [
            'registrationEnabled' => $settings->registrationEnabled(),
            'enableBanking' => [
                'configured' => $settings->enableBankingConfigured(),
                'appId' => $settings->get(PlatformSettingKey::EnableBankingAppId),
                'source' => $this->enableBankingSource($settings),
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
}
