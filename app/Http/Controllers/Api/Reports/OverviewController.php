<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportWindowRequest;
use App\Http\Resources\Reports\OverviewReportResource;
use App\Queries\OverviewReport;
use Dedoc\Scramble\Attributes\Response;

class OverviewController extends Controller
{
    /**
     * Overview report
     *
     * Windowed summary (income, outgoing buckets, result, coverage) plus a
     * per-period series — the same aggregation that powers the dashboard.
     */
    #[Response(200, type: 'array{data: array{interval: string, from: string, to: string, currency: string|null, summary: array{in: float, out: float, result: float, income: float, bills: float, consumption: float, investments: float, savings: float, uncategorized: float, coverage: array{eligible: int, converted: int, missing: int}}, series: array<int, array{period: string, label: string, complete: bool, income: float, bills: float, consumption: float, investments: float, savings: float, uncategorized: float, result: float, missing: int}>}}')]
    public function __invoke(ReportWindowRequest $request): OverviewReportResource
    {
        abort_unless($request->user()?->tokenCan('read'), 403);

        [$start, $end] = $request->window($request->user());

        return new OverviewReportResource(
            OverviewReport::for($request->user(), $start, $end, $request->reportInterval()),
        );
    }
}
