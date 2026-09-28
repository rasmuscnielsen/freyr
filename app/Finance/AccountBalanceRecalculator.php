<?php

namespace App\Finance;

use App\Models\BankAccount;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the balance of an account without a bank connection.
 *
 * With an opening balance the figure is simply opening + the sum of all
 * movements, recomputed on every change. Without one, the stored figure is
 * whatever the provider or import last reported and each manual write nudges
 * it by its delta — the balance just moves, no snapshot bookkeeping. $delta is
 * the change caused by the current write (new minus old movement) and is
 * ignored once an opening balance exists, since the total is recomputed.
 */
class AccountBalanceRecalculator
{
    public function recalculate(BankAccount $bankAccount, string $delta = '0'): void
    {
        DB::transaction(function () use ($bankAccount, $delta): void {
            $account = BankAccount::query()
                ->whereKey($bankAccount->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($account->hasBankConnection()) {
                return;
            }

            if ($account->opening_balance === null) {
                if ($account->current_balance !== null) {
                    if (bccomp($delta, '0', 10) === 0) {
                        return;
                    }

                    $account->update([
                        'current_balance' => MoneyMath::round(bcadd((string) $account->current_balance, $delta, 10)),
                        'last_updated_at' => now(),
                    ]);

                    return;
                }

                $account->update([
                    'current_balance' => $this->movementTotal($account, '0'),
                    'last_known_posting_date' => $account->postings()->max('date'),
                    'last_updated_at' => now(),
                ]);

                return;
            }

            $account->update([
                'current_balance' => $this->movementTotal($account, (string) $account->opening_balance),
                'last_known_posting_date' => $account->postings()->max('date'),
                'last_updated_at' => now(),
            ]);
        });

        $bankAccount->refresh();
    }

    /**
     * Basis plus the sum of account-currency movements, or null when a
     * posting's movement is unknown — a partial sum must not pose as a total.
     */
    private function movementTotal(BankAccount $account, string $basis): ?string
    {
        if ($account->postings()->whereNull('original_amount')->exists()) {
            return null;
        }

        return MoneyMath::round(bcadd(
            $basis,
            (string) ($account->postings()->sum('original_amount') ?: '0'),
            10,
        ));
    }
}
