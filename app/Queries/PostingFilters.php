<?php

namespace App\Queries;

use App\Models\Posting;
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
                    $from = $values[0] ?? null;
                    $to = $values[1] ?? null;

                    if (filled($from)) {
                        $query->where('date', '>=', $from);
                    }

                    if (filled($to)) {
                        $query->where('date', '<=', $to);
                    }
                }),
            )
            ->allowedSorts('date', 'amount', 'description', 'id')
            ->defaultSort('-date', '-id');
    }
}
