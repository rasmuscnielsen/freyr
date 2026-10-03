<?php

use App\Console\Commands\SyncConnectedBankAccountsCommand;
use App\Jobs\GeneratePostingEmbeddings;
use App\Models\BankConnection;
use Illuminate\Support\Facades\Schedule;

Schedule::command(SyncConnectedBankAccountsCommand::class)
    ->everyThreeHours()
    ->withoutOverlapping();

Schedule::job(new GeneratePostingEmbeddings)
    ->hourly()
    ->withoutOverlapping();

Schedule::command('model:prune', ['--model' => BankConnection::class])
    ->daily();
