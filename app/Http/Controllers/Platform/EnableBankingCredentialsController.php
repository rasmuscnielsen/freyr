<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreEnableBankingCredentialsRequest;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;

class EnableBankingCredentialsController extends Controller
{
    public function update(StoreEnableBankingCredentialsRequest $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validated();

        $settings->storeEnableBankingCredentials($data['app_id'], $request->privateKeyContents());

        return back();
    }

    public function destroy(PlatformSettings $settings): RedirectResponse
    {
        $settings->forgetEnableBankingCredentials();

        return back();
    }
}
