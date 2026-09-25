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
        DB::update(<<<'SQL'
            UPDATE postings
            SET balance = chain.running_balance
            FROM (
                SELECT id, CAST(? AS NUMERIC) + SUM(amount) OVER (ORDER BY date, id) AS running_balance
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
        $opening = number_format(
            (float) $bookedBalance - (float) $bankAccount->postings()->sum('amount'),
            2,
            '.',
            '',
        );

        DB::update(<<<'SQL'
            UPDATE postings
            SET balance = chain.running_balance
            FROM (
                SELECT id, CAST(? AS NUMERIC) + SUM(amount) OVER (ORDER BY date, id) AS running_balance
                FROM postings
                WHERE bank_account_id = ?
            ) AS chain
            WHERE postings.id = chain.id
        SQL, [$opening, $bankAccount->id]);

        $bankAccount->update(['opening_balance' => $opening]);
    }
}
