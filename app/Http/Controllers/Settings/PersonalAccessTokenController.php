<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePersonalAccessTokenRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PersonalAccessTokenController extends Controller
{
    public function store(StorePersonalAccessTokenRequest $request): RedirectResponse
    {
        $token = $request->user()->createToken(
            name: $request->validated('name'),
            scopes: ['read', 'write', 'mcp:use'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Personal access token created.')]);

        return back()->with('plainTextPersonalAccessToken', $token->accessToken);
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        $deleted = $request->user()
            ->tokens()
            ->whereKey($token)
            ->delete();

        abort_unless($deleted === 1, 404);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Personal access token revoked.')]);

        return back();
    }
}
