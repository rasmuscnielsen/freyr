<?php

namespace App\Finance;

use App\Models\BankAccount;
use App\Models\Posting;
use Illuminate\Support\Facades\DB;

/**
 * Maintains running balances on postings of bank-connected accounts whose
 * provider supplies `balance_after_transaction` checkpoints
 * (`BankConnectionAccount::provides_running_balance`). The newest stored
 * checkpoint anchors the chain; later checkpoint-less postings continue it
 * as `anchor + cumulative amount`. Balances are never derived from balance
 * summaries — an unverifiable feed simply keeps no running balance.
 */
class SyncedBalanceChainWriter
{
    public function write(BankAccount $bankAccount): void
    {
        $anchor = $bankAccount->postings()
            ->whereNotNull('balance')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if ($anchor instanceof Posting) {
            $this->extendAfterAnchor($bankAccount, $anchor);
        }
    }

    /**
     * Checkpoint-less postings after the newest known balance continue the
     * chain as `anchor + cumulative amount`, in (date, id) order.
     */
    private function extendAfterAnchor(BankAccount $bankAccount, Posting $anchor): void
    {
        // Account-currency movements only. An unknown movement (null
        // original_amount) cuts the chain: rows after it stay null rather
        // than accumulating a figure that is off by an unknown amount.
        DB::update(<<<'SQL'
            UPDATE postings
            SET balance = chain.running_balance
            FROM (
                SELECT id,
                    CASE
                        WHEN SUM(CASE WHEN original_amount IS NULL THEN 1 ELSE 0 END)
                                 OVER (ORDER BY date, id) > 0
                        THEN NULL
                        ELSE CAST(? AS NUMERIC) + SUM(original_amount) OVER (ORDER BY date, id)
                    END AS running_balance
                FROM postings
                WHERE bank_account_id = ?
                    AND balance IS NULL
                    AND (date, id) > (?, ?)
            ) AS chain
            WHERE postings.id = chain.id
        SQL, [$anchor->balance, $bankAccount->id, $anchor->date->toDateString(), $anchor->id]);
    }
}
