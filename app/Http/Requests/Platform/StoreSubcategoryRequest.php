<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\Platform\Concerns\ValidatesSubcategoryExpenseType;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubcategoryRequest extends FormRequest
{
    use ValidatesSubcategoryExpenseType;

    /**
     * Authorization is enforced by the `platform-admin` middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:100'],
            'expense_type' => $this->expenseTypeRules(),
        ];
    }

    /**
     * Resolve the parent category from the submitted `category_id`.
     */
    protected function targetCategory(): ?Category
    {
        $categoryId = $this->input('category_id');

        if ($categoryId === null) {
            return null;
        }

        return Category::query()->find($categoryId);
    }
}
