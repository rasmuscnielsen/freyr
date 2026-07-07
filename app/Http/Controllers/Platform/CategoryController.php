<?php

namespace App\Http\Controllers\Platform;

use App\Enums\CategoryType;
use App\Enums\ExpenseType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreCategoryRequest;
use App\Http\Requests\Platform\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        $categories = Category::query()
            ->with(['subcategories' => function ($query): void {
                $query->withCount('postings')->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return Inertia::render('platform/categories/index', [
            'categories' => $categories->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'type' => $category->type->value,
                'subcategories' => $category->subcategories->map(fn (Subcategory $subcategory): array => [
                    'id' => $subcategory->id,
                    'name' => $subcategory->name,
                    'expense_type' => $subcategory->expense_type?->value,
                    'postings_count' => $subcategory->postings_count,
                ])->all(),
            ])->all(),
            'categoryTypes' => array_map(
                static fn (CategoryType $type): string => $type->value,
                CategoryType::cases(),
            ),
            'expenseTypes' => array_map(
                static fn (ExpenseType $type): string => $type->value,
                ExpenseType::cases(),
            ),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Category::query()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'hyphenated_name' => Str::slug($data['name']),
            'legacy_id' => null,
        ]);

        return back();
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->validated();
        $previousType = $category->type;
        $nextType = CategoryType::from($data['type']);

        $category->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'hyphenated_name' => Str::slug($data['name']),
        ]);

        $this->syncSubcategoryExpenseTypes($category, $previousType, $nextType);

        return back();
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return back();
    }

    /**
     * Keep subcategory `expense_type` values consistent with the parent's type
     * after a type change: default them to {@see ExpenseType::Variable} when a
     * category becomes an Expense, and null them out when it stops being one.
     */
    private function syncSubcategoryExpenseTypes(Category $category, CategoryType $previousType, CategoryType $nextType): void
    {
        if ($previousType === $nextType) {
            return;
        }

        if ($nextType === CategoryType::Expense) {
            $category->subcategories()->update(['expense_type' => ExpenseType::Variable->value]);

            return;
        }

        if ($previousType === CategoryType::Expense) {
            $category->subcategories()->update(['expense_type' => null]);
        }
    }
}
