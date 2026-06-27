<?php

use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\PostingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->name('api.')->group(function () {
    Route::apiResource('bank-accounts', BankAccountController::class)->only(['index', 'show']);
    Route::apiResource('postings', PostingController::class)->only(['index', 'show']);
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
});
