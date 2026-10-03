<?php

namespace App\Actions;

use App\Concerns\LinksBankConnectionAccounts;
use App\Enums\SyncHistory;
use App\Models\BankAccount;
use App\Models\BankConnectionAccount;
use Illuminate\Validation\ValidationException;

final class LinkBankConnectionAccount
{
    use LinksBankConnectionAccounts;

    public function handle(BankConnectionAccount $remote, BankAccount $local, SyncHistory $history = SyncHistory::Max): void
    {
        // The feed's account currency must match the local account's so its
        // movements and reported balance keep one currency.
        if ($remote->currency !== $local->currency) {
            throw ValidationException::withMessages([
                'bank_account_id' => "The bank feed reports {$remote->currency} but {$local->name} is a {$local->currency} account.",
            ]);
        }

        $this->applyImportCutoff($local, $history);

        $this->linkRemoteAccount($remote, $local->id);
    }
}
