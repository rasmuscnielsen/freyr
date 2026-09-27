<?php

namespace App\Finance;

use App\Models\BankAccount;
use App\Models\Posting;
use Illuminate\Support\Facades\DB;

/**
 * Maintains derived running balances on postings of bank-connected accounts.
 *
 * Banks that do not send `balance_after_transaction` leave the chain blank:
 * the first sync derives it from the bank-reported booked (CLBD) balance —
 * the implied opening is `booked − sum(amounts)` — and later syncs extend the
 * chain past the latest checkpoint. Balances the bank does provide are never
 * rewritten, so anchoring happens exactly once per account.
 */
class SyncedBalanceChainWriter
{
    public function write(BankAccount $bankAccount, ?string $bookedBalance): void
    {
        $anchor = $bankAccount->postings()
            ->whereNotNull('balance')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if ($anchor instanceof Posting) {
            $this->extendAfterAnchor($bankAccount, $anchor);
        } elseif ($bookedBalance !== null) {
            $this->deriveFromBookedBalance($bankAccount, $bookedBalance);
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

    /**
     * With no checkpoint at all the bank's booked balance anchors the tail of
     * the imported window: the implied opening is stored and every posting
     * gets a running balance that lands exactly on the booked figure.
     */
    private function deriveFromBookedBalance(BankAccount $bankAccount, string $bookedBalance): void
    {
        // An unknown account movement anywhere in the window makes the
        // implied opening unknowable — leave the chain blank.
        if ($bankAccount->postings()->whereNull('original_amount')->exists()) {
            return;
        }

        $opening = bcsub(
            MoneyMath::round($bookedBalance),
            MoneyMath::round((string) ($bankAccount->postings()->sum('original_amount') ?: '0')),
            2,
        );

        DB::update(<<<'SQL'
            UPDATE postings
            SET balance = chain.running_balance
            FROM (
                SELECT id, CAST(? AS NUMERIC) + SUM(original_amount) OVER (ORDER BY date, id) AS running_balance
                FROM postings
                WHERE bank_account_id = ?
            ) AS chain
            WHERE postings.id = chain.id
        SQL, [$opening, $bankAccount->id]);

        // 'derived': this opening comes from the bank's own booked balance,
        // so a matching reconciliation is by construction, not proof.
        $bankAccount->update([
            'opening_balance' => $opening,
            'opening_balance_origin' => 'derived',
        ]);
    }
}
