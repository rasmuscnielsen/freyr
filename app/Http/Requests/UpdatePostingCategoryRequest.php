<?php

namespace App\Http\Requests;

use App\Models\Posting;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePostingCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $posting = $this->route('posting');

        return $posting instanceof Posting
            && $this->user()?->is($posting->user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'categorized_subcategory_id' => ['nullable', 'integer', 'exists:subcategories,id'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'is_extraordinary' => ['sometimes', 'boolean'],
        ];
    }
}
