<?php

namespace App\Http\Requests\Api;

use App\Enums\CategoryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query input for the read-only postings API. Filters are passed on to
 * PostingFilters as `filter[...]`; validating them here turns malformed
 * input into a 422 instead of an empty or silently unfiltered result.
 */
class ListPostingsRequest extends FormRequest
{
    public const INCLUDES = ['bank_account', 'category'];

    public function authorize(): bool
    {
        return (bool) $this->user()?->tokenCan('read');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            /** Comma-separated bank account ids. */
            'filter.account' => ['sometimes', 'string', 'regex:/^\d+(,\d+)*$/'],
            'filter.category' => ['sometimes', 'integer'],
            'filter.subcategory' => ['sometimes', 'integer'],
            'filter.type' => ['sometimes', Rule::enum(CategoryType::class)],
            'filter.uncategorized' => ['sometimes', 'boolean'],
            'filter.extraordinary' => ['sometimes', 'boolean'],
            'filter.text' => ['sometimes', 'string', 'max:255'],
            /** Legacy `from,to` booking date range; prefer `date_from`/`date_to`. */
            'filter.posted_between' => ['sometimes', 'string', 'regex:/^(\d{4}-\d{2}-\d{2})?,(\d{4}-\d{2}-\d{2})?$/'],
            /** Inclusive lower bound on the booking date (YYYY-MM-DD). */
            'filter.date_from' => ['sometimes', 'date_format:Y-m-d'],
            /** Inclusive upper bound on the booking date (YYYY-MM-DD). */
            'filter.date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:filter.date_from'],
            /** Inclusive lower bound on the reporting-currency amount. */
            'filter.amount_min' => ['sometimes', 'numeric'],
            /** Inclusive upper bound on the reporting-currency amount. */
            'filter.amount_max' => ['sometimes', 'numeric', 'gte:filter.amount_min'],
            /** Only postings created or changed at or after this ISO 8601 timestamp. */
            'filter.updated_since' => ['sometimes', 'date'],
            /** `date` (oldest first) or `-date` (newest first, default). Ties break on id. */
            'sort' => ['sometimes', Rule::in(['date', '-date'])],
            /** Comma-separated relations to embed: `bank_account`, `category`. Defaults to both; pass an empty value for neither. */
            'include' => ['sometimes', 'nullable', 'string', 'regex:/^(('.implode('|', self::INCLUDES).')(,|$))*$/'],
            /** Add `meta.totals` (count and amount sum of the whole filtered set). */
            'with_totals' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    /**
     * @return list<string>
     */
    public function includes(): array
    {
        if (! $this->has('include')) {
            return self::INCLUDES;
        }

        return array_values(array_filter(explode(',', (string) $this->query('include'))));
    }
}
