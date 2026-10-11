<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\Platform\Concerns\ValidatesSubcategoryExpenseGroup;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSubcategoryRequest extends FormRequest
{
    use ValidatesSubcategoryExpenseGroup;

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
            'expense_group' => $this->expenseGroupRules(),
        ];
    }

    /**
     * Validate the expense group against the selected destination category.
     */
    protected function targetCategory(): ?Category
    {
        return Category::query()->find($this->integer('category_id'));
    }
}
