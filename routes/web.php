<?php

use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankAccountPostingController;
use App\Http\Controllers\BankConnectionAccountController;
use App\Http\Controllers\BankConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Platform\CategoryController;
use App\Http\Controllers\Platform\EnableBankingCredentialsController;
use App\Http\Controllers\Platform\OpenAiCredentialsController;
use App\Http\Controllers\Platform\RegistrationController;
use App\Http\Controllers\Platform\SettingsController;
use App\Http\Controllers\Platform\SimilaritySettingsController;
use App\Http\Controllers\Platform\SubcategoryController;
use App\Http\Controllers\PostingController;
use App\Http\Controllers\RegionSetupController;
use App\Http\Controllers\SpiirImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/login'))->name('home');

Route::middleware('auth')->prefix('region-setup')->name('region-setup.')->group(function () {
    Route::get('/', [RegionSetupController::class, 'edit'])->name('edit');
    Route::post('/', [RegionSetupController::class, 'store'])->name('store');
});

Route::middleware(['auth', 'verified', 'requires-region-setup'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('bank-connections', [BankConnectionController::class, 'index'])->name('bank-connections.index');
    Route::post('bank-connections', [BankConnectionController::class, 'store'])->name('bank-connections.store');
    Route::get('bank-connections/callback', [BankConnectionController::class, 'callback'])->name('bank-connections.callback');
    Route::post('bank-connections/{bankConnection}/renew', [BankConnectionController::class, 'renew'])->name('bank-connections.renew');
    Route::post('bank-connections/{bankConnection}/archive', [BankConnectionController::class, 'archive'])->name('bank-connections.archive');
    Route::post('bank-connections/{bankConnection}/unarchive', [BankConnectionController::class, 'unarchive'])->name('bank-connections.unarchive');
    Route::post('bank-connections/{bankConnection}/sync', [BankConnectionController::class, 'sync'])->name('bank-connections.sync');
    Route::post('bank-connections/{bankConnection}/accounts', [BankConnectionController::class, 'mapAccounts'])->name('bank-connections.accounts.map');
    Route::delete('bank-connections/{bankConnection}', [BankConnectionController::class, 'destroy'])->name('bank-connections.destroy');
    Route::patch('bank-connection-accounts/{bankConnectionAccount}', [BankConnectionAccountController::class, 'update'])->name('bank-connection-accounts.update');
    Route::post('bank-connection-accounts/{bankConnectionAccount}/sync', [BankConnectionAccountController::class, 'sync'])->name('bank-connection-accounts.sync');
    Route::patch('postings', [PostingController::class, 'bulkUpdate'])->name('postings.bulk-update');
    Route::post('postings/retry-conversions', [PostingController::class, 'retryConversions'])->name('postings.retry-conversions');
    Route::get('postings/{posting}/similar', [PostingController::class, 'similar'])->name('postings.similar');
    Route::resource('postings', PostingController::class)->only(['index', 'update']);
    Route::resource('bank-accounts', BankAccountController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::resource('bank-accounts.postings', BankAccountPostingController::class)->only(['store', 'update', 'destroy'])->scoped();
});

Route::middleware(['auth', 'platform-admin', 'requires-region-setup'])->group(function () {
    Route::post('spiir-import', [SpiirImportController::class, 'store'])->name('spiir-import.store');
    Route::get('spiir-import/{import}', [SpiirImportController::class, 'show'])->name('spiir-import.show');
});

Route::middleware(['auth', 'verified', 'platform-admin', 'requires-region-setup'])->prefix('platform')->name('platform.')->group(function () {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::post('subcategories', [SubcategoryController::class, 'store'])->name('subcategories.store');
    Route::patch('subcategories/{subcategory}', [SubcategoryController::class, 'update'])->name('subcategories.update');
    Route::delete('subcategories/{subcategory}', [SubcategoryController::class, 'destroy'])->name('subcategories.destroy');

    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('settings/registration', [RegistrationController::class, 'update'])->name('settings.registration.update');
    Route::put('settings/enable-banking', [EnableBankingCredentialsController::class, 'update'])->name('settings.enable-banking.update');
    Route::delete('settings/enable-banking', [EnableBankingCredentialsController::class, 'destroy'])->name('settings.enable-banking.destroy');
    Route::put('settings/openai', [OpenAiCredentialsController::class, 'update'])->name('settings.openai.update');
    Route::delete('settings/openai', [OpenAiCredentialsController::class, 'destroy'])->name('settings.openai.destroy');
    Route::patch('settings/similarity', [SimilaritySettingsController::class, 'update'])->name('settings.similarity.update');
});

require __DIR__.'/onboarding.php';
require __DIR__.'/settings.php';
