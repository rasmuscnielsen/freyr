<?php

namespace App\Http\Controllers\Platform;

use App\Enums\PlatformSettingKey;
use App\Http\Controllers\Controller;
use App\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    public function update(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $settings->set(PlatformSettingKey::RegistrationEnabled, (bool) $data['enabled']);

        return back();
    }
}
