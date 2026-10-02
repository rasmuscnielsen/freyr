<?php

namespace App\Jobs;

use App\Models\BankConnectionAccount;
use App\Services\EnableBanking\ConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncBankConnectionAccount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public BankConnectionAccount $bankConnectionAccount) {}

    public function handle(ConnectionManager $manager): void
    {
        $manager->syncAccount($this->bankConnectionAccount);
    }

    public function uniqueId(): string
    {
        return (string) $this->bankConnectionAccount->getKey();
    }
}
