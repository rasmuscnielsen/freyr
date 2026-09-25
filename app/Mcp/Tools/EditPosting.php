<?php

namespace App\Mcp\Tools;

use App\Finance\AccountBalanceRecalculator;
use App\Http\Resources\PostingResource;
use App\Models\Posting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class EditPosting extends FreyrTool
{
    protected string $requiredScope = 'write';

    public function name(): string
    {
        return 'edit-posting';
    }

    public function description(): string
    {
        return <<<'MARKDOWN'
            Edit a single posting. `comment`, `is_extraordinary`,
            `custom_date`, and `categorized_subcategory_id` are editable on
            every posting; `date`, `description`, and `amount` only on
            postings in manual bank accounts (accounts synced via a bank
            connection are read-only for those fields). Setting the confirmed
            categorization records `categorized_by: 'mcp'`; setting it to
            null uncategorizes.
            MARKDOWN;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Posting id.'),
            'date' => $schema->string()->description('Posted date (YYYY-MM-DD). Manual accounts only.'),
            'description' => $schema->string()->description('Display description. Manual accounts only.'),
            'amount' => $schema->string()->description('Amount with up to 2 decimals; negative for debits. Manual accounts only.'),
            'custom_date' => $schema->string()->nullable()->description('User-facing date override (YYYY-MM-DD); null clears it.'),
            'comment' => $schema->string()->nullable()->description('Comment (max 2000 chars); null clears it.'),
            'is_extraordinary' => $schema->boolean()->description('Mark the posting as extraordinary.'),
            'categorized_subcategory_id' => $schema->integer()->nullable()->description('Confirmed subcategory id; null uncategorizes.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'decimal:0,2'],
            'custom_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_extraordinary' => ['sometimes', 'boolean'],
            'categorized_subcategory_id' => ['sometimes', 'nullable', 'integer', 'exists:subcategories,id'],
        ]);

        $posting = Posting::query()
            ->forUser($this->actingUser())
            ->with(['bankAccount', 'categorizedSubcategory.category', 'subcategory'])
            ->find($validated['id']);

        if ($posting === null) {
            return Response::error("Posting {$validated['id']} not found.");
        }

        $manualOnly = array_intersect(array_keys($validated), ['date', 'description', 'amount']);

        if ($manualOnly !== [] && $posting->bankAccount->hasBankConnection()) {
            return Response::error('date, description, and amount can only be changed on postings in manual bank accounts — this account is synced via a bank connection.');
        }

        if (array_key_exists('categorized_subcategory_id', $validated) && $validated['categorized_subcategory_id'] !== null) {
            $validated['categorized_by'] = 'mcp';
        } elseif (array_key_exists('categorized_subcategory_id', $validated)) {
            $validated['categorized_by'] = null;
        }

        $posting->update(collect($validated)->except('id')->all());

        if (! $posting->bankAccount->hasBankConnection()) {
            app(AccountBalanceRecalculator::class)->recalculate($posting->bankAccount);
        }

        $posting->refresh()->load(['bankAccount', 'categorizedSubcategory.category', 'subcategory']);

        return Response::structured([
            'posting' => (new PostingResource($posting))->toArray(request()),
        ]);
    }
}
