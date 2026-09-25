<?php

use App\Console\Commands\SyncConnectedBankAccountsCommand;
use App\Models\BankConnection;
use Illuminate\Support\Facades\Schedule;

Schedule::command(SyncConnectedBankAccountsCommand::class)
    ->dailyAt('05:30')
    ->withoutOverlapping();

Schedule::command('model:prune', ['--model' => BankConnection::class])
    ->daily();
