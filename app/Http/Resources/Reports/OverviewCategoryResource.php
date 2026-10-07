<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the overview category report: a category's totals over the
 * requested window, its subcategory rollup, largest postings, and — when
 * `interval` was given — a per-period `series`.
 */
class OverviewCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'type' => $this->resource['type'],
            'bucket' => $this->resource['bucket'],
            'total' => $this->resource['total'],
            'missing' => $this->resource['missing'],
            'subcategories' => $this->resource['subcategories'],
            'top_postings' => $this->resource['topPostings'],
            'series' => $this->when(isset($this->resource['series']), $this->resource['series'] ?? []),
        ];
    }
}
