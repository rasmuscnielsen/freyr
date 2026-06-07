<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'bank_key' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'last_booked_balance' => ['nullable', 'numeric'],
            'last_available_balance' => ['nullable', 'numeric'],
            'account_type' => ['nullable', 'string', 'max:255'],
            'account_subcategory_key' => ['nullable', 'string', 'max:255'],
            'last_known_posting_date' => ['nullable', 'date'],
            'is_active' => ['required', 'boolean'],
            'is_favorite' => ['required', 'boolean'],
        ];
    }
}
