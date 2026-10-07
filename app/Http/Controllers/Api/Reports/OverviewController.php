<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportWindowRequest;
use App\Http\Resources\Reports\OverviewReportResource;
use App\Queries\OverviewReport;

class OverviewController extends Controller
{
    public function __invoke(ReportWindowRequest $request): OverviewReportResource
    {
        abort_unless($request->user()?->tokenCan('read'), 403);

        [$start, $end] = $request->window($request->user());

        return new OverviewReportResource(
            OverviewReport::for($request->user(), $start, $end, $request->reportInterval()),
        );
    }
}
