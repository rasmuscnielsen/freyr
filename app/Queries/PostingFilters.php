<?php

namespace App\Queries;

use App\Models\Posting;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Shared filter/sort definition for posting listings, used by both the
 * postings page and the MCP `list-postings` tool. Accepts an optional
 * request to read `filter[...]`/`sort` input from instead of the current
 * HTTP request (the MCP transport synthesizes one from tool arguments).
 */
final class PostingFilters
{
    /**
     * @param  Builder<Posting>  $query
     * @return QueryBuilder<Posting>
     */
    public static function apply(Builder $query, ?Request $request = null): QueryBuilder
    {
        return QueryBuilder::for($query, $request)
            ->allowedFilters(
                AllowedFilter::exact('account', 'bank_account_id'),
                AllowedFilter::callback('category', function (Builder $query, mixed $value): void {
                    $query->whereHas('categorizedSubcategory', fn (Builder $query): Builder => $query->where('category_id', $value));
                }),
                AllowedFilter::exact('subcategory', 'categorized_subcategory_id'),
                AllowedFilter::callback('type', function (Builder $query, mixed $value): void {
                    $query->whereHas('categorizedSubcategory.category', fn (Builder $query): Builder => $query->where('type', $value));
                }),
                AllowedFilter::callback('uncategorized', function (Builder $query, mixed $value): void {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                        $query->whereNull('categorized_subcategory_id');
                    }
                }),
                AllowedFilter::exact('extraordinary', 'is_extraordinary'),
                AllowedFilter::callback('text', function (Builder $query, mixed $value): void {
                    // `filter[text][]=…` arrives as an array (hand-edited or
                    // shared URLs); join it rather than letting the cast 500.
                    $needle = trim(is_array($value) ? implode(' ', Arr::flatten($value)) : (string) $value);

                    if ($needle === '') {
                        return;
                    }

                    $query->where(function (Builder $query) use ($needle): void {
                        $query
                            ->whereLike('description', "%{$needle}%")
                            ->orWhereLike('original_description', "%{$needle}%")
                            ->orWhereLike('comment', "%{$needle}%");
                    });
                }),
                AllowedFilter::callback('posted_between', function (Builder $query, mixed $value): void {
                    $values = is_array($value) ? $value : explode(',', (string) $value);

                    self::whereDateBound($query, '>=', $values[0] ?? null);
                    self::whereDateBound($query, '<=', $values[1] ?? null);
                }),
                AllowedFilter::callback('date_from', fn (Builder $query, mixed $value) => self::whereDateBound($query, '>=', $value)),
                AllowedFilter::callback('date_to', fn (Builder $query, mixed $value) => self::whereDateBound($query, '<=', $value)),
                AllowedFilter::callback('amount_min', fn (Builder $query, mixed $value) => self::whereAmountBound($query, '>=', $value)),
                AllowedFilter::callback('amount_max', fn (Builder $query, mixed $value) => self::whereAmountBound($query, '<=', $value)),
                AllowedFilter::callback('updated_since', function (Builder $query, mixed $value): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    try {
                        $since = CarbonImmutable::parse($value);
                    } catch (InvalidFormatException) {
                        return;
                    }

                    $query->where('updated_at', '>=', $since->utc());
                }),
            )
            ->allowedSorts('date', 'amount', 'description', 'id')
            ->defaultSort('-date', '-id');
    }

    /**
     * Bounds on the booking `date`. Malformed values are ignored rather than
     * reaching the database; the API rejects them up front in validation.
     *
     * @param  Builder<Posting>  $query
     */
    private static function whereDateBound(Builder $query, string $operator, mixed $value): void
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            // The upper bound compares against the next day: SQLite stores
            // cast dates with a time part, so `<= 'Y-m-d'` would drop that day.
            $operator === '<='
                ? $query->where('date', '<', CarbonImmutable::parse($value)->addDay()->toDateString())
                : $query->where('date', $operator, $value);
        }
    }

    /**
     * @param  Builder<Posting>  $query
     */
    private static function whereAmountBound(Builder $query, string $operator, mixed $value): void
    {
        if (is_numeric($value)) {
            $query->where('amount', $operator, $value);
        }
    }
}
