<?php

use App\Console\Commands\SyncConnectedBankAccountsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command(SyncConnectedBankAccountsCommand::class)
    ->daily()
    ->withoutOverlapping();
