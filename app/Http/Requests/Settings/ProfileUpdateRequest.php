<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $country = $this->input('country_code', $this->user()->country_code);

        return [
            ...$this->profileRules($this->user()->id),
            'country_code' => ['sometimes', 'string', 'size:2', Rule::in(array_keys(config('regions.countries')))],
            'timezone' => [
                'sometimes',
                'string',
                'max:64',
                Rule::in(config("regions.timezones.{$country}") ?? []),
            ],
            // The reporting currency is fixed after setup and only ever moves
            // through the Spiir import boundary — never through a profile edit.
            'reporting_currency' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reporting_currency.prohibited' => 'The reporting currency cannot be changed.',
            'timezone.in' => 'The selected timezone is not valid for the chosen country.',
        ];
    }
}
