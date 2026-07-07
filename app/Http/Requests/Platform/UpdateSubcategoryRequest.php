<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\Platform\Concerns\ValidatesSubcategoryExpenseType;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSubcategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'expense_type' => $this->expenseTypeRules(),
        ];
    }

    /**
     * The parent category is fixed on update; resolve it from the bound
     * subcategory so the `expense_type` rule matches its current parent.
     */
    protected function targetCategory(): ?Category
    {
        $subcategory = $this->route('subcategory');

        return $subcategory instanceof Subcategory ? $subcategory->category : null;
    }
}
