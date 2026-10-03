<?php

namespace App\Actions;

use App\Models\BankConnectionAccount;

final class IgnoreBankConnectionAccount
{
    public function handle(BankConnectionAccount $remote): void
    {
        $detachedAccount = $remote->bankAccount;

        $remote->update([
            'status' => BankConnectionAccount::STATUS_IGNORED,
            'bank_account_id' => null,
        ]);

        // Keep the provider snapshot; only calculate a manual total after the
        // user sets an opening balance.
        if ($detachedAccount !== null && ! $detachedAccount->bankConnectionAccounts()->exists()) {
            $detachedAccount->update(['opening_balance' => null]);
        }
    }
}
