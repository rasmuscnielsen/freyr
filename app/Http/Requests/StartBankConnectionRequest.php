<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartBankConnectionRequest extends FormRequest
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
            'aspsp_name' => ['nullable', 'string', 'max:255'],
            'aspsp_country' => ['nullable', 'string', 'size:2'],
        ];
    }
}
