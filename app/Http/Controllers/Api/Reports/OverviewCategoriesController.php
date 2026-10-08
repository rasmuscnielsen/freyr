<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportWindowRequest;
use App\Http\Resources\Reports\OverviewCategoryResource;
use App\Queries\OverviewReport;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OverviewCategoriesController extends Controller
{
    /**
     * Overview categories
     *
     * Per-category totals for the window (type, bucket, missing amounts,
     * subcategory rollups). With `interval` given, each category also
     * reports its totals per period.
     */
    #[Response(200, type: 'array{data: list<array{id: int|null, name: string, type: string, bucket: string|null, total: float, missing: int, subcategories: list<array{id: int|null, name: string, amount: float}>, top_postings: list<array{description: string|null, date: string, amount: float}>, series?: list<array{period: string, total: float}>}>}')]
    public function __invoke(ReportWindowRequest $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->tokenCan('read'), 403);

        [$start, $end] = $request->window($request->user());
        $report = OverviewReport::for($request->user(), $start, $end, $request->reportInterval());

        $categories = $report->categories();

        // With `interval` given, each category additionally reports its
        // totals per period (month or year) — keyed by `{type}:{id}` since
        // the same id can appear once per flow.
        if ($request->has('interval')) {
            $series = $report->categorySeries();

            foreach ($categories as &$category) {
                $key = $category['type'].':'.($category['id'] ?? 'uncategorized');
                $category['series'] = $series[$key] ?? [];
            }
        }

        return OverviewCategoryResource::collection($categories);
    }
}
