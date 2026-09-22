<?php

namespace App\Mcp\Tools;

use App\Http\Resources\BankAccountResource;
use App\Models\BankAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class ListBankAccounts extends FreyrTool
{
    public function name(): string
    {
        return 'list-bank-accounts';
    }

    public function description(): string
    {
        return 'List the user\'s bank accounts with name, bank name, currency, current balance, and whether the account is synced via a bank connection (synced accounts are read-only for posting field edits).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = BankAccount::query()
            ->whereBelongsTo($this->actingUser())
            ->orderByDesc('is_active')
            ->orderBy('bank_name')
            ->orderBy('name')
            ->get();

        return Response::structured([
            'accounts' => $accounts
                ->map(fn (BankAccount $account): array => (new BankAccountResource($account))->toArray(request()))
                ->all(),
        ]);
    }
}
