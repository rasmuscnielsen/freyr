<?php

use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\PostingController;
use App\Http\Controllers\Api\Reports\OverviewCategoriesController;
use App\Http\Controllers\Api\Reports\OverviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->name('api.')->group(function () {
    Route::apiResource('bank-accounts', BankAccountController::class)->only(['index', 'show']);
    Route::apiResource('postings', PostingController::class)->only(['index', 'show']);
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
    Route::get('reports/overview', OverviewController::class)->name('reports.overview');
    Route::get('reports/overview/categories', OverviewCategoriesController::class)->name('reports.overview.categories');
});
