<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the overview category report: a category's totals over the
 * requested window, its subcategory rollup, largest postings, and — when
 * `interval` was given — a per-period `series`.
 *
 * @property-read array{id: int|null, name: string, type: string, bucket: string|null, total: float, missing: int, net_total: float, subcategories: array<int, array{id: int|null, name: string, amount: float, net_amount: float}>, topPostings: array<int, array{description: string|null, date: string, amount: float}>, series?: array<int, array{period: string, total: float, net: float}>} $resource
 */
class OverviewCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int|null, name: string, type: string, bucket: string|null, total: float, missing: int, net_total: float, subcategories: array<int, array{id: int|null, name: string, amount: float, net_amount: float}>, top_postings: array<int, array{description: string|null, date: string, amount: float}>, series?: array<int, array{period: string, total: float, net: float}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'type' => $this->resource['type'],
            'bucket' => $this->resource['bucket'],
            'total' => $this->resource['total'],
            'net_total' => $this->resource['net_total'],
            'missing' => $this->resource['missing'],
            'subcategories' => $this->resource['subcategories'],
            'top_postings' => $this->resource['topPostings'],
            'series' => $this->when(isset($this->resource['series']), $this->resource['series'] ?? []),
        ];
    }
}
