<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkUpdatePostingCategoriesRequest;
use App\Http\Requests\UpdatePostingCategoryRequest;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Queries\PostingFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $baseQuery = Posting::query()
            ->forUser($user)
            ->with(['bankAccount', 'categorizedSubcategory.category']);

        $query = PostingFilters::apply($baseQuery);

        $postings = $query
            ->paginate(75)
            ->withQueryString()
            ->through(fn (Posting $posting): array => $this->postingPayload($posting));

        return Inertia::render('postings/index', [
            'postings' => $postings,
            'filters' => $request->query('filter', []),
            'sort' => $request->query('sort', '-date,-id'),
            'accounts' => BankAccount::query()
                ->whereBelongsTo($user)
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'bank_name', 'currency', 'is_active']),
            'categories' => Category::query()
                ->with(['subcategories' => fn ($query) => $query->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->sortBy(fn (Category $category): int => $category->type->displayOrder())
                ->values()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'type' => $category->type,
                    'subcategories' => $category->subcategories->map(fn ($subcategory): array => [
                        'id' => $subcategory->id,
                        'name' => $subcategory->name,
                        'type' => $category->type,
                    ]),
                ]),
        ]);
    }

    public function update(UpdatePostingCategoryRequest $request, Posting $posting): RedirectResponse
    {
        $posting->update($request->validated());

        return back();
    }

    /**
     * Recategorize a batch of postings in one go. Ids not owned by the
     * authenticated user are ignored by the ownership scope.
     */
    public function bulkUpdate(BulkUpdatePostingCategoriesRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Posting::query()
            ->forUser($request->user())
            ->whereIn('id', $validated['ids'])
            ->update(['categorized_subcategory_id' => $validated['categorized_subcategory_id']]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function postingPayload(Posting $posting): array
    {
        return [
            'id' => $posting->id,
            'date' => $posting->date?->toDateString(),
            'description' => $posting->description,
            'original_description' => $posting->original_description,
            'comment' => $posting->comment,
            'amount' => (float) $posting->amount,
            'currency' => $posting->currency,
            'is_extraordinary' => $posting->is_extraordinary,
            'bank_account' => [
                'id' => $posting->bankAccount->id,
                'name' => $posting->bankAccount->name,
                'bank_name' => $posting->bankAccount->bank_name,
            ],
            'category' => $posting->categorizedSubcategory?->category === null ? null : [
                'id' => $posting->categorizedSubcategory->category->id,
                'name' => $posting->categorizedSubcategory->category->name,
                'type' => $posting->categorizedSubcategory->category->type,
            ],
            'subcategory' => $posting->categorizedSubcategory === null ? null : [
                'id' => $posting->categorizedSubcategory->id,
                'name' => $posting->categorizedSubcategory->name,
                'type' => $posting->categorizedSubcategory->category?->type,
            ],
        ];
    }
}
