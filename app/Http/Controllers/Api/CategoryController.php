<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeRead($request);

        return CategoryResource::collection(
            Category::query()
                ->with(['subcategories' => fn ($query) => $query->orderBy('name')])
                ->orderBy('type')
                ->orderBy('name')
                ->get(),
        );
    }

    public function show(Request $request, Category $category): CategoryResource
    {
        $this->authorizeRead($request);

        return new CategoryResource($category->load(['subcategories' => fn ($query) => $query->orderBy('name')]));
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()?->tokenCan('read'), 403);
    }
}
