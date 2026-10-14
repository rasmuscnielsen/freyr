<?php

namespace App\Actions;

use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a category (with its subcategories) or a single subcategory.
 *
 * The database nulls posting references through the FKs without touching
 * the rows, so the affected postings get their `updated_at` bumped for API
 * `changed_since` sync. That happens after the delete commits: a timestamp
 * written earlier could predate the start of a sync that still read the
 * old row.
 */
final class DeleteCategory
{
    public function handle(Category|Subcategory $category): void
    {
        $subcategoryIds = $category instanceof Category
            ? $category->subcategories()->pluck('id')->all()
            : [$category->id];

        $postingIds = $subcategoryIds === [] ? [] : Posting::query()
            ->where(fn ($query) => $query
                ->whereIn('categorized_subcategory_id', $subcategoryIds)
                ->orWhereIn('subcategory_id', $subcategoryIds))
            ->pluck('id')
            ->all();

        $category->delete();

        if ($postingIds === []) {
            return;
        }

        DB::afterCommit(function () use ($postingIds): void {
            foreach (array_chunk($postingIds, 1000) as $chunk) {
                Posting::query()->whereKey($chunk)->update(['updated_at' => now()]);
            }
        });
    }
}
