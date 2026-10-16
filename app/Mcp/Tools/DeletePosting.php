<?php

namespace App\Mcp\Tools;

use App\Actions\DeletePosting as DeletePostingAction;
use App\Actions\RecalculateAccountBalance;
use App\Models\Posting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class DeletePosting extends FreyrTool
{
    protected string $requiredScope = 'write';

    public function name(): string
    {
        return 'delete-posting';
    }

    public function description(): string
    {
        return 'Soft-delete an owned posting in a manual bank account, clear counter-entry links to it, and recalculate the account balance. Postings in bank-synced accounts cannot be deleted. Returns the deleted posting id.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Posting id to delete.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);

        $posting = Posting::query()
            ->forUser($this->actingUser())
            ->with('bankAccount')
            ->find($validated['id']);

        if ($posting === null) {
            return Response::error("Posting {$validated['id']} not found.");
        }

        if ($posting->bankAccount->hasBankConnection()) {
            return Response::error('Postings can only be deleted from manual bank accounts — this account is synced via a bank connection.');
        }

        $delta = bcmul((string) ($posting->original_amount ?? '0'), '-1', 10);
        app(DeletePostingAction::class)->handle($posting);
        app(RecalculateAccountBalance::class)->handle($posting->bankAccount, $delta);

        return Response::structured(['deleted_id' => $posting->id]);
    }
}
