<?php

namespace App\Http\Resources\Reports;

use App\Queries\OverviewReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OverviewReport
 */
class OverviewReportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{interval: string, from: string, to: string, currency: string|null, summary: array{in: float, out: float, result: float, income: float, bills: float, living_costs: float, lifestyle: float, other: float, investments: float, savings: float, uncategorized: float, coverage: array{eligible: int, converted: int, missing: int}}, series: array<int, array{period: string, label: string, complete: bool, income: float, bills: float, living_costs: float, lifestyle: float, other: float, investments: float, savings: float, uncategorized: float, result: float, missing: int}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'interval' => $this->resource->interval()->value,
            'from' => $this->resource->start()->toDateString(),
            'to' => $this->resource->end()->toDateString(),
            'currency' => $request->user()?->reporting_currency,
            'summary' => $this->resource->summary(),
            'series' => $this->resource->series(),
        ];
    }
}
