<?php

namespace App\Mcp\Tools;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class ListCategories extends FreyrTool
{
    public function name(): string
    {
        return 'list-categories';
    }

    public function description(): string
    {
        return <<<'MARKDOWN'
            The categorization vocabulary: every category with its type
            (income/expense/investment/saving/exclude) and each of its
            subcategories (id, name, expense type, and `hints` describing what
            belongs there). Reference these ids in `categorize-postings` and
            `edit-posting`.
            MARKDOWN;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $categories = Category::query()
            ->with('subcategories')
            ->get()
            ->sortBy(fn (Category $category) => $category->type->displayOrder())
            ->values();

        return Response::structured([
            'categories' => $categories
                ->map(fn (Category $category): array => (new CategoryResource($category))->toArray(request()))
                ->all(),
        ]);
    }
}
