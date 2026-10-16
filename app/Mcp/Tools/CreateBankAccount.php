<?php

namespace App\Mcp\Tools;

use App\Actions\CreateManualBankAccount;
use App\Http\Requests\StoreBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class CreateBankAccount extends FreyrTool
{
    protected string $requiredScope = 'write';

    public function name(): string
    {
        return 'create-bank-account';
    }

    public function description(): string
    {
        return 'Create a manual bank account for the acting user, without a bank connection. The opening balance defaults to zero and is in the account currency, not the reporting currency. Returns the created account.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Account name (max 255 chars).'),
            'currency' => $schema->string()->required()->description('Three-letter account currency, e.g. DKK.'),
            'opening_balance' => $schema->string()->description('Opening balance in account currency, up to 2 decimals; defaults to 0.00.'),
            'bank_key' => $schema->string()->nullable()->description('Optional bank identifier (max 255 chars).'),
            'bank_name' => $schema->string()->nullable()->description('Optional bank name (max 255 chars).'),
            'account_type' => $schema->string()->nullable()->description('Optional account type (max 255 chars).'),
            'last_known_posting_date' => $schema->string()->nullable()->description('Last known posting date (YYYY-MM-DD).'),
            'is_active' => $schema->boolean()->description('Whether the account is active; defaults to true.'),
            'is_favorite' => $schema->boolean()->description('Whether the account is a favorite; defaults to false.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate((new StoreBankAccountRequest)->rules());
        $account = app(CreateManualBankAccount::class)->handle($this->actingUser(), $validated);

        return Response::structured([
            'account' => (new BankAccountResource($account->refresh()))->toArray(request()),
        ]);
    }
}
