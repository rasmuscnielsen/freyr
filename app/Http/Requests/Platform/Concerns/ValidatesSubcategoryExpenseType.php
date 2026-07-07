<?php

namespace App\Http\Requests\Platform\Concerns;

use App\Enums\CategoryType;
use App\Enums\ExpenseType;
use App\Models\Category;
use Illuminate\Validation\Rule;

/**
 * Shared validation for a subcategory's `expense_type`, which is only
 * meaningful when its parent category is of type {@see CategoryType::Expense}:
 * it is required for Expense parents and must be absent for every other type.
 */
trait ValidatesSubcategoryExpenseType
{
    /**
     * Resolve the parent category the subcategory is being validated against.
     */
    abstract protected function targetCategory(): ?Category;

    /**
     * @return array<int, mixed>
     */
    protected function expenseTypeRules(): array
    {
        if ($this->targetCategory()?->type === CategoryType::Expense) {
            return ['required', Rule::enum(ExpenseType::class)];
        }

        return ['nullable', 'prohibited'];
    }
}
