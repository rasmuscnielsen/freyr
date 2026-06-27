<?php

namespace App\Console\Commands;

use App\Jobs\SyncBankConnectionAccountJob;
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
            ->orderBy('id')
            ->lazyById()
            ->each(function (BankConnectionAccount $bankConnectionAccount) use (&$count): void {
                SyncBankConnectionAccountJob::dispatch($bankConnectionAccount);
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
