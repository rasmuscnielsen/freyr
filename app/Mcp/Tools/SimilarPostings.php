<?php

namespace App\Mcp\Tools;

use App\Http\Resources\PostingResource;
use App\Models\Posting;
use App\Queries\PostingSimilarity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class SimilarPostings extends FreyrTool
{
    public function name(): string
    {
        return 'similar-postings';
    }

    public function description(): string
    {
        return <<<'MARKDOWN'
            Find the postings most similar to a given posting by description
            meaning (embedding similarity, requires the platform's similar-
            postings feature). Each match includes `similarity` (0–1) and its
            confirmed/suggested categorization — the evidence behind a
            `suggested_subcategory`, and the fastest way to decide how to
            categorize a posting yourself.
            MARKDOWN;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Posting id — from `list-postings` results.'),
            'limit' => $schema->integer()->description('Max matches to return (1–20, default 8).'),
        ];
    }

    public function handle(Request $request, PostingSimilarity $similarity): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'limit' => ['integer', 'min:1', 'max:20'],
        ]);

        $posting = Posting::query()
            ->forUser($this->actingUser())
            ->with(['bankAccount', 'categorizedSubcategory.category', 'subcategory'])
            ->find($validated['id']);

        if ($posting === null) {
            return Response::error("Posting {$validated['id']} not found.");
        }

        if (! $similarity->supported()) {
            return Response::error('Similar postings are not enabled — the platform needs an OpenAI API key and the feature turned on.');
        }

        if ($posting->description_embedding === null) {
            return Response::error('This posting is not analyzed yet — it will be picked up by the next similarity sweep.');
        }

        return Response::structured([
            'similar' => $similarity->similarTo($posting, $validated['limit'] ?? 8)
                ->map(fn (Posting $match): array => [
                    'similarity' => $match->similarity,
                    ...(new PostingResource($match))->toArray(request()),
                ])
                ->values()
                ->all(),
        ]);
    }
}
