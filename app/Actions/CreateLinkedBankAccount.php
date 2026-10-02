<?php

namespace App\Actions;

use App\Concerns\LinksBankConnectionAccounts;
use App\Enums\SyncHistory;
use App\Models\BankAccount;
use App\Models\BankConnectionAccount;
use Illuminate\Support\Str;

final class CreateLinkedBankAccount
{
    use LinksBankConnectionAccounts;

    public function handle(BankConnectionAccount $remote, string $name, SyncHistory $history = SyncHistory::Max): BankAccount
    {
        $remote->loadMissing('bankConnection');

        $bankAccount = $remote->bankConnection->user->bankAccounts()->create([
            'legacy_id' => BankAccount::ENABLE_BANKING_LEGACY_ID_PREFIX.Str::uuid()->toString(),
            'ignore_postings_before' => $history->cutoffDate(),
            'name' => $name,
            'bank_key' => 'enable_banking_'.$remote->bankConnection->aspsp_country,
            'bank_name' => $remote->bankConnection->aspsp_name,
            'currency' => $remote->currency,
            'account_type' => $remote->cash_account_type,
            'account_number' => $remote->account_number,
        ]);

        $this->linkRemoteAccount($remote, $bankAccount->id);

        return $bankAccount;
    }
}
