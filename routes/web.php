<?php

use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankAccountPostingController;
use App\Http\Controllers\BankConnectionAccountController;
use App\Http\Controllers\BankConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Platform\CategoryController;
use App\Http\Controllers\Platform\SettingsController;
use App\Http\Controllers\Platform\SubcategoryController;
use App\Http\Controllers\PostingController;
use App\Http\Controllers\SpiirImportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('bank-connections', [BankConnectionController::class, 'index'])->name('bank-connections.index');
    Route::post('bank-connections', [BankConnectionController::class, 'store'])->name('bank-connections.store');
    Route::get('bank-connections/callback', [BankConnectionController::class, 'callback'])->name('bank-connections.callback');
    Route::post('bank-connections/{bankConnection}/renew', [BankConnectionController::class, 'renew'])->name('bank-connections.renew');
    Route::post('bank-connections/{bankConnection}/sync', [BankConnectionController::class, 'sync'])->name('bank-connections.sync');
    Route::delete('bank-connections/{bankConnection}', [BankConnectionController::class, 'destroy'])->name('bank-connections.destroy');
    Route::patch('bank-connection-accounts/{bankConnectionAccount}', [BankConnectionAccountController::class, 'update'])->name('bank-connection-accounts.update');
    Route::post('bank-connection-accounts/{bankConnectionAccount}/sync', [BankConnectionAccountController::class, 'sync'])->name('bank-connection-accounts.sync');
    Route::patch('postings', [PostingController::class, 'bulkUpdate'])->name('postings.bulk-update');
    Route::resource('postings', PostingController::class)->only(['index', 'update']);
    Route::resource('bank-accounts', BankAccountController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::resource('bank-accounts.postings', BankAccountPostingController::class)->only(['store', 'update', 'destroy'])->scoped();
});

Route::middleware(['auth', 'platform-admin'])->group(function () {
    Route::post('spiir-import', [SpiirImportController::class, 'store'])->name('spiir-import.store');
    Route::get('spiir-import/{import}', [SpiirImportController::class, 'show'])->name('spiir-import.show');
});

Route::middleware(['auth', 'verified', 'platform-admin'])->prefix('platform')->name('platform.')->group(function () {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::post('subcategories', [SubcategoryController::class, 'store'])->name('subcategories.store');
    Route::patch('subcategories/{subcategory}', [SubcategoryController::class, 'update'])->name('subcategories.update');
    Route::delete('subcategories/{subcategory}', [SubcategoryController::class, 'destroy'])->name('subcategories.destroy');

    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('settings/registration', [SettingsController::class, 'updateRegistration'])->name('settings.registration.update');
    Route::put('settings/enable-banking', [SettingsController::class, 'storeEnableBanking'])->name('settings.enable-banking.update');
    Route::delete('settings/enable-banking', [SettingsController::class, 'destroyEnableBanking'])->name('settings.enable-banking.destroy');
});

require __DIR__.'/onboarding.php';
require __DIR__.'/settings.php';
