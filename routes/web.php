<?php

use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankConnectionAccountController;
use App\Http\Controllers\BankConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PostingController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('bank-connections', [BankConnectionController::class, 'index'])->name('bank-connections.index');
    Route::post('bank-connections', [BankConnectionController::class, 'store'])->name('bank-connections.store');
    Route::get('bank-connections/callback', [BankConnectionController::class, 'callback'])->name('bank-connections.callback');
    Route::post('bank-connections/{bankConnection}/renew', [BankConnectionController::class, 'renew'])->name('bank-connections.renew');
    Route::patch('bank-connection-accounts/{bankConnectionAccount}', [BankConnectionAccountController::class, 'update'])->name('bank-connection-accounts.update');
    Route::post('bank-connection-accounts/{bankConnectionAccount}/sync', [BankConnectionAccountController::class, 'sync'])->name('bank-connection-accounts.sync');
    Route::resource('postings', PostingController::class)->only(['index', 'update']);
    Route::resource('bank-accounts', BankAccountController::class)->only(['index', 'store', 'update', 'destroy']);
});

require __DIR__.'/settings.php';
