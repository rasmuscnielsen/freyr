<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BankAccountResource;
use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BankAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeRead($request);

        return BankAccountResource::collection(
            BankAccount::query()
                ->whereBelongsTo($request->user())
                ->orderByDesc('is_active')
                ->orderBy('bank_name')
                ->orderBy('name')
                ->paginate($this->perPage($request)),
        );
    }

    public function show(Request $request, BankAccount $bankAccount): BankAccountResource
    {
        $this->authorizeRead($request);

        abort_unless($bankAccount->user_id === $request->user()->id, 404);

        return new BankAccountResource($bankAccount);
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()?->tokenCan('read'), 403);
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 50), 1), 100);
    }
}
