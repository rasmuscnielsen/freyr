<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Tool;

abstract class FreyrTool extends Tool
{
    /**
     * Scope required in addition to `mcp:use` — which only opens the MCP
     * transport. Read tools layer on `read`, write tools layer on `write`,
     * and a user resolved without a token (stdio `mcp:start`) is trusted
     * outright.
     */
    protected string $requiredScope = 'read';

    public function shouldRegister(): bool
    {
        return $this->actingUser() !== null && $this->userCanAct();
    }

    /**
     * The user this tool acts as: the bearer-token user over HTTP, or —
     * over stdio, where no authentication exists — the instance's sole user.
     * Resolves null when neither applies.
     */
    protected function actingUser(): ?User
    {
        $user = Auth::user();

        if ($user instanceof User) {
            return $user;
        }

        return User::query()->count() === 1 ? User::query()->first() : null;
    }

    protected function userCanAct(): bool
    {
        $user = $this->actingUser();

        if ($user === null) {
            return false;
        }

        // Scoped tokens are restricted to what they were minted for; a
        // session-authenticated user or the stdio sole-user has no token at
        // all and acts as themselves.
        if ($user->token() === null) {
            return true;
        }

        return $user->tokenCan('mcp:use') && $user->tokenCan($this->requiredScope);
    }
}
