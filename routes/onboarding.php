<?php

use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SpiirImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('no-users')->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'welcome'])->name('onboarding.welcome');
    Route::post('onboarding/account', [OnboardingController::class, 'storeAccount'])->name('onboarding.account.store');
});

Route::middleware(['auth', 'platform-admin', 'onboarding-not-completed'])->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('region', [OnboardingController::class, 'region'])->name('region');
    Route::post('region', [OnboardingController::class, 'storeRegion'])->name('region.store');
    Route::get('enable-banking', [OnboardingController::class, 'enableBanking'])->name('enable-banking');
    Route::post('enable-banking', [OnboardingController::class, 'storeEnableBanking'])->name('enable-banking.store');
    Route::post('enable-banking/skip', [OnboardingController::class, 'skipEnableBanking'])->name('enable-banking.skip');
    Route::get('spiir', [OnboardingController::class, 'spiir'])->name('spiir');
    Route::post('spiir', [SpiirImportController::class, 'store'])->name('spiir.store');
    Route::get('similarity', [OnboardingController::class, 'similarity'])->name('similarity');
    Route::post('similarity', [OnboardingController::class, 'storeSimilarity'])->name('similarity.store');
    Route::post('complete', [OnboardingController::class, 'complete'])->name('complete');
});
