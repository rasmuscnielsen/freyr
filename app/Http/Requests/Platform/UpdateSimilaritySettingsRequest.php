<?php

namespace App\Http\Requests\Platform;

use App\Support\PlatformSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSimilaritySettingsRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route middleware.
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
            'enabled' => ['sometimes', 'boolean'],
            'since' => ['sometimes', 'string', 'in:1y,2y,5y,all'],
            'auto_categorize' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Similarity features need an OpenAI key: either stored earlier or the
     * environment fallback.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('enabled') && ! app(PlatformSettings::class)->openAiConfigured()) {
                $validator->errors()->add('enabled', 'Add an OpenAI API key before enabling similar postings.');
            }
        });
    }
}
