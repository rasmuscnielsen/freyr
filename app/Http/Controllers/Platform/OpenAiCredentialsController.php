<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Jobs\GeneratePostingEmbeddingsJob;
use App\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpenAiCredentialsController extends Controller
{
    public function update(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $settings->storeOpenAiApiKey(trim($data['api_key']));

        if ($settings->similarityAvailable()) {
            GeneratePostingEmbeddingsJob::dispatch()->afterCommit();
        }

        return back();
    }

    /**
     * Removing the key also disables similarity features — they cannot run
     * without it.
     */
    public function destroy(PlatformSettings $settings): RedirectResponse
    {
        $settings->forgetOpenAiApiKey();

        return back();
    }
}
