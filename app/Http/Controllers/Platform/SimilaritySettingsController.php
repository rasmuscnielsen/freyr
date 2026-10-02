<?php

namespace App\Http\Controllers\Platform;

use App\Enums\PlatformSettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateSimilaritySettingsRequest;
use App\Jobs\GeneratePostingEmbeddingsJob;
use App\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;

class SimilaritySettingsController extends Controller
{
    public function update(UpdateSimilaritySettingsRequest $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validated();

        if (array_key_exists('enabled', $data)) {
            $settings->set(PlatformSettingKey::SimilarityEnabled, (bool) $data['enabled']);
        }

        if (array_key_exists('since', $data)) {
            $settings->set(PlatformSettingKey::SimilaritySince, $data['since']);
        }

        if (array_key_exists('auto_categorize', $data)) {
            $settings->set(PlatformSettingKey::SimilarityAutoCategorize, (bool) $data['auto_categorize']);
        }

        if ($settings->similarityAvailable()) {
            GeneratePostingEmbeddingsJob::dispatch()->afterCommit();
        }

        return back();
    }
}
