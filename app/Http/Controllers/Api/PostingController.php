<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostingResource;
use App\Models\Posting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeRead($request);

        return PostingResource::collection(
            Posting::query()
                ->forUser($request->user())
                ->with(['bankAccount', 'categorizedSubcategory.category'])
                ->latest('date')
                ->latest('id')
                ->paginate($this->perPage($request)),
        );
    }

    public function show(Request $request, Posting $posting): PostingResource
    {
        $this->authorizeRead($request);

        abort_unless($posting->user_id === $request->user()->id, 404);

        return new PostingResource($posting->load(['bankAccount', 'categorizedSubcategory.category']));
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()?->tokenCan('read'), 403);
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 50), 1), 100);
    }
}
