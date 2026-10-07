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
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'interval' => $this->resource->interval()->value,
            'from' => $this->resource->start()->toDateString(),
            'to' => $this->resource->end()->toDateString(),
            'currency' => $request->user()?->reporting_currency,
            'summary' => $this->resource->summary(),
            'series' => $this->resource->series()->all(),
        ];
    }
}
