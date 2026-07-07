<?php

namespace App\Http\Requests;

use App\Models\BankAccount;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePostingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this rcequest.
     */
    public function authorize(): bool
    {
        $bankAccount = $this->route('bank_account');

        return $bankAccount instanceof BankAccount
            && $this->user()?->is($bankAccount->user)
            && ! $bankAccount->hasBankConnection();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'required', 'date'],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'amount' => ['sometimes', 'required', 'numeric', 'decimal:0,2'],
            'categorized_subcategory_id' => ['sometimes', 'nullable', 'integer', 'exists:subcategories,id'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
