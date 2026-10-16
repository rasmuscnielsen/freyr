<?php

namespace App\Mcp\Tools;

use App\Actions\CreateManualPosting;
use App\Http\Requests\StorePostingRequest;
use App\Http\Resources\PostingResource;
use App\Models\BankAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class CreatePosting extends FreyrTool
{
    protected string $requiredScope = 'write';

    public function name(): string
    {
        return 'create-posting';
    }

    public function description(): string
    {
        return 'Create a posting in an owned manual bank account. Bank-synced accounts are read-only for creation. Amount is the account-currency movement (negative for debits); foreign reporting amounts remain pending conversion. Confirmed categorization records categorized_by: mcp. Returns the created posting.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'bank_account_id' => $schema->integer()->required()->description('Manual bank account id from list-bank-accounts.'),
            'date' => $schema->string()->required()->description('Posted date (YYYY-MM-DD).'),
            'description' => $schema->string()->required()->description('Display description (max 255 chars).'),
            'amount' => $schema->string()->required()->description('Account-currency amount with up to 2 decimals; negative for debits.'),
            'categorized_subcategory_id' => $schema->integer()->nullable()->description('Confirmed subcategory id from list-categories.'),
            'comment' => $schema->string()->nullable()->description('Optional comment (max 2000 chars).'),
            'is_extraordinary' => $schema->boolean()->description('Mark the posting as extraordinary.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'bank_account_id' => ['required', 'integer'],
            ...(new StorePostingRequest)->rules(),
        ]);

        $account = BankAccount::query()
            ->whereBelongsTo($this->actingUser())
            ->find($validated['bank_account_id']);

        if ($account === null) {
            return Response::error("Bank account {$validated['bank_account_id']} not found.");
        }

        if ($account->hasBankConnection()) {
            return Response::error('Postings can only be created in manual bank accounts — this account is synced via a bank connection.');
        }

        unset($validated['bank_account_id']);

        if (($validated['categorized_subcategory_id'] ?? null) !== null) {
            $validated['categorized_by'] = 'mcp';
        }

        $posting = app(CreateManualPosting::class)->handle($account, $validated);
        $posting->refresh()->load(['bankAccount', 'categorizedSubcategory.category', 'subcategory']);

        return Response::structured([
            'posting' => (new PostingResource($posting))->toArray(request()),
        ]);
    }
}
