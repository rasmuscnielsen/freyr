<?php

namespace App\Mcp\Tools;

use App\Models\Posting;
use App\Models\Subcategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class CategorizePostings extends FreyrTool
{
    protected string $requiredScope = 'write';

    public function name(): string
    {
        return 'categorize-postings';
    }

    public function description(): string
    {
        return <<<'MARKDOWN'
            Set the confirmed categorization for up to 200 postings at once
            (pass `categorized_subcategory_id: null` to uncategorize). Ids not
            owned by the user are reported in `skipped` — never applied.
            Records `categorized_by: 'mcp'` as provenance. Get subcategory ids
            from `list-categories`.
            MARKDOWN;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ids' => $schema->array()->items($schema->integer())->min(1)->max(200)->required()->description('Posting ids to categorize (max 200).'),
            'categorized_subcategory_id' => $schema->integer()->nullable()->required()->description('Subcategory id to confirm on every posting — null to uncategorize.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'categorized_subcategory_id' => ['present', 'nullable', 'integer', 'exists:subcategories,id'],
        ]);

        $subcategoryId = $validated['categorized_subcategory_id'];

        $applied = Posting::query()
            ->forUser($this->actingUser())
            ->whereIn('id', $validated['ids'])
            ->pluck('id')
            ->all();

        Posting::query()
            ->forUser($this->actingUser())
            ->whereIn('id', $applied)
            ->update([
                'categorized_subcategory_id' => $subcategoryId,
                'categorized_by' => $subcategoryId === null ? null : 'mcp',
            ]);

        $skipped = array_values(array_diff($validated['ids'], $applied));

        $subcategory = $subcategoryId === null
            ? null
            : Subcategory::query()->with('category:id,name,type')->find($subcategoryId, ['id', 'name', 'category_id']);

        return Response::structured([
            'applied' => $applied,
            'skipped' => $skipped,
            'categorized_subcategory' => $subcategory === null ? null : [
                'id' => $subcategory->id,
                'name' => $subcategory->name,
                'category' => [
                    'id' => $subcategory->category->id,
                    'name' => $subcategory->category->name,
                    'type' => $subcategory->category->type,
                ],
            ],
        ]);
    }
}
