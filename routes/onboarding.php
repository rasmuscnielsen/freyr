<?php

use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SpiirImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('no-users')->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'welcome'])->name('onboarding.welcome');
    Route::post('onboarding/account', [OnboardingController::class, 'storeAccount'])->name('onboarding.account.store');
});

Route::middleware(['auth', 'platform-admin', 'onboarding-not-completed'])->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('registration', [OnboardingController::class, 'registration'])->name('registration');
    Route::post('registration', [OnboardingController::class, 'storeRegistration'])->name('registration.store');
    Route::get('enable-banking', [OnboardingController::class, 'enableBanking'])->name('enable-banking');
    Route::post('enable-banking', [OnboardingController::class, 'storeEnableBanking'])->name('enable-banking.store');
    Route::post('enable-banking/skip', [OnboardingController::class, 'skipEnableBanking'])->name('enable-banking.skip');
    Route::get('spiir', [OnboardingController::class, 'spiir'])->name('spiir');
    Route::post('spiir', [SpiirImportController::class, 'store'])->name('spiir.store');
    Route::post('complete', [OnboardingController::class, 'complete'])->name('complete');
});
