<?php

namespace App\Mcp\Tools;

use App\Http\Resources\PostingResource;
use App\Models\Posting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class GetPosting extends FreyrTool
{
    public function name(): string
    {
        return 'get-posting';
    }

    public function description(): string
    {
        return 'Fetch the full detail of a single posting by id — including both the confirmed and the suggested categorization, comment, and provenance.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Posting id — from `list-postings` results.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $posting = Posting::query()
            ->forUser($this->actingUser())
            ->with(['bankAccount', 'categorizedSubcategory.category', 'subcategory'])
            ->find($validated['id']);

        if ($posting === null) {
            return Response::error("Posting {$validated['id']} not found.");
        }

        return Response::structured([
            'posting' => (new PostingResource($posting))->toArray(request()),
        ]);
    }
}
