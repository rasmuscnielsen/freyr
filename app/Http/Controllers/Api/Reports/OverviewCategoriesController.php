<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportWindowRequest;
use App\Http\Resources\Reports\OverviewCategoryResource;
use App\Queries\OverviewReport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OverviewCategoriesController extends Controller
{
    public function __invoke(ReportWindowRequest $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->tokenCan('read'), 403);

        [$start, $end] = $request->window($request->user());
        $report = OverviewReport::for($request->user(), $start, $end, $request->reportInterval());

        $categories = $report->categories();

        // With `interval` given, each category additionally reports its
        // totals per period (month or year).
        if ($request->has('interval')) {
            $series = $report->categorySeries();

            foreach ($categories as &$category) {
                $category['series'] = $series[(string) ($category['id'] ?? 'uncategorized')] ?? [];
            }
        }

        return OverviewCategoryResource::collection($categories);
    }
}
