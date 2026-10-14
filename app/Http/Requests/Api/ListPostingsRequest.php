<?php

namespace App\Http\Requests\Api;

use App\Enums\CategoryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Query input for the read-only postings API. Filters are passed on to
 * PostingFilters as `filter[...]`; validating them here turns malformed
 * input into a 422 instead of an empty or silently unfiltered result, and
 * keeps out-of-range values from reaching the database as a 500.
 */
class ListPostingsRequest extends FormRequest
{
    public const INCLUDES = ['bank_account', 'category'];

    private const DATE = '/^(\d{4})-(\d{2})-(\d{2})$/';

    public function authorize(): bool
    {
        return (bool) $this->user()?->tokenCan('read');
    }

    /**
     * The spec types these as booleans, so generated clients send
     * `true`/`false`; Laravel's `boolean` rule only accepts 1/0.
     */
    protected function prepareForValidation(): void
    {
        $query = $this->query->all();

        foreach (['with_totals', 'filter.uncategorized', 'filter.extraordinary'] as $key) {
            $value = Arr::get($query, $key);

            if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                Arr::set($query, $key, strtolower($value) === 'true' ? '1' : '0');
            }
        }

        $this->query->replace($query);
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
            'filter.category' => ['sometimes', 'integer', 'min:1'],
            'filter.subcategory' => ['sometimes', 'integer', 'min:1'],
            'filter.type' => ['sometimes', Rule::enum(CategoryType::class)],
            'filter.uncategorized' => ['sometimes', 'boolean'],
            'filter.extraordinary' => ['sometimes', 'boolean'],
            'filter.text' => ['sometimes', 'string', 'max:255'],
            /** Legacy `from,to` booking date range; prefer `date_from`/`date_to`. */
            'filter.posted_between' => ['sometimes', 'string', 'regex:/^(\d{4}-\d{2}-\d{2})?,(\d{4}-\d{2}-\d{2})?$/'],
            /** Inclusive lower bound on the booking date (YYYY-MM-DD). */
            'filter.date_from' => ['sometimes', 'date_format:Y-m-d'],
            /** Inclusive upper bound on the booking date (YYYY-MM-DD). */
            'filter.date_to' => ['sometimes', 'date_format:Y-m-d'],
            /** Inclusive lower bound on the reporting-currency amount. */
            'filter.amount_min' => ['sometimes', 'numeric'],
            /** Inclusive upper bound on the reporting-currency amount. */
            'filter.amount_max' => ['sometimes', 'numeric'],
            /**
             * Only postings created, changed or deleted at or after this ISO
             * 8601 timestamp, e.g. `2026-05-02T09:00:00Z`. Deleted postings
             * are included, marked by a non-null `deleted_at`. URL-encode a
             * `+` offset (`%2B02:00`) or use `Z`.
             */
            'filter.changed_since' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', 'date'],
            /** `date` (oldest first) or `-date` (newest first, default). Ties break on id. */
            'sort' => ['sometimes', Rule::in(['date', '-date'])],
            /** Comma-separated relations to embed: `bank_account`, `category`. Defaults to both; pass an empty value for neither. */
            'include' => ['sometimes', 'nullable', 'string', 'regex:/^(('.implode('|', self::INCLUDES).')(,|$))*$/'],
            /** Add `meta.totals` (count and amount sum of the whole filtered set). */
            'with_totals' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            /** Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`. */
            'cursor' => ['sometimes', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $filter = (array) $this->query('filter', []);

            foreach (explode(',', (string) ($filter['account'] ?? '1')) as $id) {
                if (! self::isId($id)) {
                    $validator->errors()->add('filter.account', 'The account ids must be valid ids.');
                    break;
                }
            }

            if (isset($filter['posted_between'])) {
                [$from, $to] = explode(',', (string) $filter['posted_between']);

                if (($from !== '' && ! self::isDate($from)) || ($to !== '' && ! self::isDate($to)) || ($from !== '' && $to !== '' && $from > $to)) {
                    $validator->errors()->add('filter.posted_between', 'The posted between range must be two valid dates in order.');
                }
            }

            if (isset($filter['date_from'], $filter['date_to']) && $filter['date_from'] > $filter['date_to']) {
                $validator->errors()->add('filter.date_to', 'The date to must be on or after date from.');
            }

            if (isset($filter['amount_min'], $filter['amount_max']) && (float) $filter['amount_min'] > (float) $filter['amount_max']) {
                $validator->errors()->add('filter.amount_max', 'The amount max must be at least amount min.');
            }

            if ($this->has('cursor') && ! self::isValidCursor((string) $this->query('cursor'))) {
                $validator->errors()->add('cursor', 'The cursor is invalid.');
            }
        });
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

    /**
     * Cursors are client-supplied, so their parameters are checked against
     * the `(date, id)` ordering before they are bound into a query.
     */
    private static function isValidCursor(string $encoded): bool
    {
        $cursor = Cursor::fromEncoded($encoded);

        if ($cursor === null) {
            return false;
        }

        $parameters = $cursor->toArray();
        ksort($parameters);

        return array_keys($parameters) === ['_pointsToNextItems', 'date', 'id']
            && is_bool($parameters['_pointsToNextItems'])
            && is_string($parameters['date'])
            // SQLite hands back cast dates with a midnight time part.
            && self::isDate((string) preg_replace('/ 00:00:00$/', '', $parameters['date']))
            && self::isId($parameters['id']);
    }

    private static function isDate(string $value): bool
    {
        return preg_match(self::DATE, $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * A positive id that fits a signed bigint.
     */
    private static function isId(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }
}
