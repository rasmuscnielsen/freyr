<?php

namespace App\Http\Requests\Platform\Concerns;

use App\Enums\CategoryType;
use App\Enums\ExpenseGroup;
use App\Models\Category;
use Illuminate\Validation\Rule;

/**
 * Shared validation for a subcategory's `expense_group`, which is only
 * meaningful when its parent category is of type {@see CategoryType::Expense}:
 * it is optional for Expense parents and must be absent for every other type.
 */
trait ValidatesSubcategoryExpenseGroup
{
    /**
     * Resolve the parent category the subcategory is being validated against.
     */
    abstract protected function targetCategory(): ?Category;

    /**
     * @return array<int, mixed>
     */
    protected function expenseGroupRules(): array
    {
        if ($this->targetCategory()?->type === CategoryType::Expense) {
            return ['nullable', Rule::enum(ExpenseGroup::class)];
        }

        return ['nullable', 'prohibited'];
    }
}
