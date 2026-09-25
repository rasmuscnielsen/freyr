<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryType;
use App\Http\Resources\PostingResource;
use App\Models\Posting;
use App\Queries\PostingFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class ListPostings extends FreyrTool
{
    public function name(): string
    {
        return 'list-postings';
    }

    public function description(): string
    {
        return <<<'MARKDOWN'
            List the user's postings (bank transactions), newest first.
            Filter by bank account, confirmed category/subcategory (or only
            uncategorized), category type, extraordinary flag, free text
            (description/original description/comment), or a posted-date range.
            Paginated — use `per_page`/`page`.
            MARKDOWN;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->integer()->description('Bank account id — see `list-bank-accounts`.'),
            'category' => $schema->integer()->description('Category id of the confirmed categorization.'),
            'subcategory' => $schema->integer()->description('Subcategory id of the confirmed categorization.'),
            'type' => $schema->string()->enum(collect(CategoryType::cases())->map->value->all())->description('Filter by category type.'),
            'uncategorized' => $schema->boolean()->description('Only postings without a confirmed categorization.'),
            'extraordinary' => $schema->boolean()->description('Only postings marked as extraordinary.'),
            'text' => $schema->string()->description('Substring match on description, original description, or comment.'),
            'posted_from' => $schema->string()->description('Inclusive start of the posted-date range (YYYY-MM-DD).'),
            'posted_to' => $schema->string()->description('Inclusive end of the posted-date range (YYYY-MM-DD).'),
            'sort' => $schema->string()->description("Comma-separated sort fields; prefix '-' for descending. Allowed: date, amount, description, id."),
            'per_page' => $schema->integer()->description('Results per page (1–100, default 25).'),
            'page' => $schema->integer()->description('Page number (default 1).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'account' => ['integer'],
            'category' => ['integer'],
            'subcategory' => ['integer'],
            'type' => ['string', 'in:'.collect(CategoryType::cases())->map->value->implode(',')],
            'uncategorized' => ['boolean'],
            'extraordinary' => ['boolean'],
            'text' => ['string', 'max:255'],
            'posted_from' => ['nullable', 'date_format:Y-m-d', 'required_with:posted_to'],
            'posted_to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['string', 'max:255'],
            'per_page' => ['integer', 'min:1', 'max:100'],
            'page' => ['integer', 'min:1'],
        ]);

        $filter = [];
        foreach (['account', 'category', 'subcategory', 'type', 'uncategorized', 'extraordinary', 'text'] as $key) {
            if (array_key_exists($key, $validated)) {
                $filter[$key] = $validated[$key];
            }
        }

        if (array_key_exists('posted_from', $validated) || array_key_exists('posted_to', $validated)) {
            $filter['posted_between'] = implode(',', [
                $validated['posted_from'] ?? '',
                $validated['posted_to'] ?? '',
            ]);
        }

        $query = PostingFilters::apply(
            Posting::query()
                ->forUser($this->actingUser())
                ->with(['bankAccount', 'categorizedSubcategory.category', 'subcategory']),
            HttpRequest::create('/', 'GET', array_filter([
                'filter' => $filter,
                'sort' => $validated['sort'] ?? null,
            ])),
        );

        $postings = $query->paginate(
            perPage: $validated['per_page'] ?? 25,
            page: $validated['page'] ?? 1,
        );

        return Response::structured([
            'postings' => collect($postings->items())
                ->map(fn (Posting $posting): array => (new PostingResource($posting))->toArray(request()))
                ->all(),
            'pagination' => [
                'current_page' => $postings->currentPage(),
                'last_page' => $postings->lastPage(),
                'per_page' => $postings->perPage(),
                'total' => $postings->total(),
            ],
        ]);
    }
}
