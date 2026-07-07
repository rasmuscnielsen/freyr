<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePostingCategoryRequest;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class PostingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $baseQuery = Posting::query()
            ->forUser($user)
            ->with(['bankAccount', 'categorizedSubcategory.category']);

        $query = QueryBuilder::for($baseQuery)
            ->allowedFilters(
                AllowedFilter::exact('account', 'bank_account_id'),
                AllowedFilter::callback('category', function (Builder $query, mixed $value): void {
                    $query->whereHas('categorizedSubcategory', fn (Builder $query): Builder => $query->where('category_id', $value));
                }),
                AllowedFilter::exact('subcategory', 'categorized_subcategory_id'),
                AllowedFilter::callback('type', function (Builder $query, mixed $value): void {
                    $query->whereHas('categorizedSubcategory.category', fn (Builder $query): Builder => $query->where('type', $value));
                }),
                AllowedFilter::callback('uncategorized', function (Builder $query, mixed $value): void {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                        $query->whereNull('categorized_subcategory_id');
                    }
                }),
                AllowedFilter::exact('extraordinary', 'is_extraordinary'),
                AllowedFilter::callback('text', function (Builder $query, mixed $value): void {
                    $needle = trim((string) $value);

                    if ($needle === '') {
                        return;
                    }

                    $query->where(function (Builder $query) use ($needle): void {
                        $query
                            ->whereLike('description', "%{$needle}%")
                            ->orWhereLike('original_description', "%{$needle}%")
                            ->orWhereLike('comment', "%{$needle}%");
                    });
                }),
                AllowedFilter::callback('posted_between', function (Builder $query, mixed $value): void {
                    $values = is_array($value) ? $value : explode(',', (string) $value);
                    $from = $values[0] ?? null;
                    $to = $values[1] ?? null;

                    if (filled($from)) {
                        $query->where('date', '>=', $from);
                    }

                    if (filled($to)) {
                        $query->where('date', '<=', $to);
                    }
                }),
            )
            ->allowedSorts('date', 'amount', 'description', 'id')
            ->defaultSort('-date', '-id');

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
                ->orderBy('type')
                ->orderBy('name')
                ->get()
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
