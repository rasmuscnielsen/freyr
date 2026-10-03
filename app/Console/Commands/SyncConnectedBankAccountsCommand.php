<?php

namespace App\Console\Commands;

use App\Jobs\SyncBankConnectionAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:bank-connections:sync')]
#[Description('Dispatch transaction sync jobs for connected bank accounts')]
class SyncConnectedBankAccountsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;

        BankConnectionAccount::query()
            ->where('status', BankConnectionAccount::STATUS_LINKED)
            ->whereNotNull('bank_account_id')
            ->whereHas('bankAccount', fn ($query) => $query->where('is_active', true))
            ->whereHas('bankConnection', fn ($query) => $query->where('status', '!=', BankConnection::STATUS_ARCHIVED))
            ->orderBy('id')
            ->lazyById()
            ->each(function (BankConnectionAccount $bankConnectionAccount) use (&$count): void {
                SyncBankConnectionAccount::dispatch($bankConnectionAccount);
                $count++;
            });

        $this->components->info(sprintf(
            'Dispatched %d bank account sync %s.',
            $count,
            $count === 1 ? 'job' : 'jobs',
        ));

        return self::SUCCESS;
    }
}
