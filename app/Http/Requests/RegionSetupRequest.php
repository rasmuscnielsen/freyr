<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The one-time personal region setup: country, timezone, and the reporting
 * currency that all postings are converted into. The reporting currency is
 * fixed once submitted — later profile edits never touch it.
 */
class RegionSetupRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'country_code' => ['required', 'string', 'size:2', Rule::in(array_keys(config('regions.countries')))],
            'timezone' => [
                'required',
                'string',
                'max:64',
                Rule::in(collect(config('regions.timezones'))->flatten()->unique()->all()),
            ],
            'reporting_currency' => ['required', 'string', 'size:3', Rule::in(config('regions.ecb_currencies'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.in' => 'The selected timezone is not supported.',
        ];
    }
}
