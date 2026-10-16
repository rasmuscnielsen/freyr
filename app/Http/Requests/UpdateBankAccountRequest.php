<?php

namespace App\Http\Requests;

use App\Models\BankAccount;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBankAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $bankAccount = $this->route('bank_account');

        return $bankAccount instanceof BankAccount
            && $this->user()?->is($bankAccount->user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bank_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'opening_balance' => ['sometimes', 'numeric', 'decimal:0,2', 'between:-9999999999999.99,9999999999999.99'],
            'account_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'is_favorite' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
