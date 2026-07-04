<?php

use App\Console\Commands\SyncConnectedBankAccountsCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(SyncConnectedBankAccountsCommand::class)
    ->dailyAt('05:30')
    ->withoutOverlapping();
