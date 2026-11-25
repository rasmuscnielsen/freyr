<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Jobs\GeneratePostingEmbeddings;
use App\Rules\WorkingOpenAiKey;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpenAiCredentialsController extends Controller
{
    public function update(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['bail', 'required', 'string', 'min:10', 'max:255', new WorkingOpenAiKey],
        ]);

        $settings->storeOpenAiApiKey(trim($data['api_key']));

        if ($settings->similarityAvailable()) {
            GeneratePostingEmbeddings::dispatch()->afterCommit();
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
