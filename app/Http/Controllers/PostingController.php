<?php

namespace App\Http\Controllers;

use App\Finance\PostingSimilarity;
use App\Http\Requests\BulkUpdatePostingCategoriesRequest;
use App\Http\Requests\UpdatePostingCategoryRequest;
use App\Jobs\ConvertReportingAmountsJob;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Queries\PostingFilters;
use Illuminate\Http\JsonResponse;
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
            ->with(['bankAccount', 'categorizedSubcategory.category', 'subcategory.category']);

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
     * JSON detail for the posting sheet: the posting itself, its nearest
     * similar postings (when similarity features are on), and any category
     * suggestion — computing and storing one lazily when the slot is still
     * empty, the same fill the sweep job performs.
     */
    public function similar(Request $request, Posting $posting, PostingSimilarity $similarity): JsonResponse
    {
        abort_unless($posting->user_id === $request->user()->id, 404);

        $posting->loadMissing(['bankAccount', 'categorizedSubcategory.category', 'subcategory.category']);

        if ($similarity->supported()
            && $posting->categorized_subcategory_id === null
            && $posting->subcategory_id === null
            && $posting->description_embedding !== null
            && ($suggestion = $similarity->suggestionFor($posting)) !== null) {
            $posting->update(['subcategory_id' => $suggestion['subcategory_id']]);
            $posting->load('subcategory.category');
        }

        return response()->json([
            'posting' => $this->postingPayload($posting),
            'similar' => $similarity->similarTo($posting)
                ->map(fn (Posting $match): array => [
                    ...$this->postingPayload($match),
                    'similarity' => $match->similarity,
                ])
                ->values()
                ->all(),
            'similarity_enabled' => $similarity->supported(),
        ]);
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
     * Re-run reporting-currency conversion for the user's unresolved
     * postings. The job itself obeys the shared provider cooldown and
     * request limits, so this is safe to hit repeatedly.
     */
    public function retryConversions(Request $request): RedirectResponse
    {
        ConvertReportingAmountsJob::dispatch($request->user()->id)->afterCommit();

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
            'amount' => $posting->amount === null ? null : (float) $posting->amount,
            'currency' => $posting->currency,
            'original_amount' => $posting->original_amount === null ? null : (float) $posting->original_amount,
            'original_currency' => $posting->original_currency,
            'is_extraordinary' => $posting->is_extraordinary,
            'categorized_by' => $posting->categorized_by,
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
            'suggested_subcategory' => $posting->subcategory === null ? null : [
                'id' => $posting->subcategory->id,
                'name' => $posting->subcategory->name,
                'category_name' => $posting->subcategory->category?->name,
            ],
        ];
    }
}
