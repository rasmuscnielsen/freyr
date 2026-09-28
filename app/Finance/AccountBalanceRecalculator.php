<?php

namespace App\Finance;

use App\Models\BankAccount;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the current total for a manual account with a known opening balance.
 */
class AccountBalanceRecalculator
{
    public function recalculate(BankAccount $bankAccount): void
    {
        DB::transaction(function () use ($bankAccount): void {
            $account = BankAccount::query()
                ->whereKey($bankAccount->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Connected balances belong to the provider. A null opening marks
            // an imported/disconnected balance snapshot with no manual basis.
            if ($account->hasBankConnection() || $account->opening_balance === null) {
                return;
            }

            $currentBalance = $account->postings()->whereNull('original_amount')->exists()
                ? null
                : MoneyMath::round(bcadd(
                    (string) $account->opening_balance,
                    (string) ($account->postings()->sum('original_amount') ?: '0'),
                    10,
                ));

            $account->update([
                'current_balance' => $currentBalance,
                'last_known_posting_date' => $account->postings()->max('date'),
                'last_updated_at' => now(),
            ]);
        });

        $bankAccount->refresh();
    }
}
