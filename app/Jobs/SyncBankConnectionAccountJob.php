<?php

namespace App\Jobs;

use App\Finance\EnableBanking\EnableBankingConnectionManager;
use App\Models\BankConnectionAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncBankConnectionAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public BankConnectionAccount $bankConnectionAccount) {}

    /**
     * Execute the job.
     */
    public function handle(EnableBankingConnectionManager $manager): void
    {
        $manager->syncAccount($this->bankConnectionAccount);
    }

    public function uniqueId(): string
    {
        return (string) $this->bankConnectionAccount->getKey();
    }
}
