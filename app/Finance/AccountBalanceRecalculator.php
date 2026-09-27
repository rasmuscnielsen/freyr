<?php

namespace App\Finance;

use App\Models\BankAccount;
use App\Models\Posting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Maintains derived balances for bank accounts without a bank connection.
 *
 * Connected accounts carry bank-provided running balances and a bank-reported
 * current balance, so their chain is never rewritten here. Manual accounts
 * derive everything from `opening_balance` + their postings ordered by
 * (date, id): each posting's running balance is rewritten and the newest one
 * becomes the account's `current_balance`.
 */
class AccountBalanceRecalculator
{
    public function recalculate(BankAccount $bankAccount): void
    {
        if ($bankAccount->hasBankConnection()) {
            throw new InvalidArgumentException('Balances of accounts with a bank connection are owned by sync.');
        }

        DB::transaction(function () use ($bankAccount): void {
            $account = BankAccount::query()
                ->whereKey($bankAccount->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Running balances are built from account-currency movements
            // (original_amount), never reporting amounts. An unknown opening
            // or an unknown movement makes every later balance unknown too —
            // null is honest, a fabricated figure is not.
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
                ) AS chain
                WHERE postings.id = chain.id
            SQL, [$account->opening_balance, $account->id]);

            $latestPosting = $account->postings()
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->first();

            $account->update([
                'current_balance' => $latestPosting?->balance ?? $account->opening_balance,
                'last_known_posting_date' => $latestPosting?->date?->toDateString(),
                'last_updated_at' => now(),
            ]);
        });

        $bankAccount->refresh();
    }

    /**
     * Derive and persist the account's opening balance from an imported chain:
     * the oldest posting with a stored balance implies the balance held before
     * the first posting. Meant to run once, right after an import, while the
     * stored balances are still the authoritative imported values.
     */
    public function seedOpeningBalanceFromImportedChain(BankAccount $bankAccount): void
    {
        $anchor = $bankAccount->postings()
            ->whereNotNull('balance')
            ->orderBy('date')
            ->orderBy('id')
            ->first();

        if (! $anchor instanceof Posting) {
            return;
        }

        $throughAnchor = fn ($query) => $query->where(function ($nested) use ($anchor): void {
            $nested
                ->where('date', '<', $anchor->date)
                ->orWhere(fn ($inner) => $inner->where('date', $anchor->date)->where('id', '<=', $anchor->id));
        });

        // An unknown account movement before the anchor makes the implied
        // opening unknowable — leave it null rather than fabricate one.
        if ($bankAccount->postings()->where($throughAnchor)->whereNull('original_amount')->exists()) {
            return;
        }

        $amountsThroughAnchor = (string) ($bankAccount->postings()->where($throughAnchor)->sum('original_amount') ?: '0');

        $bankAccount->update([
            'opening_balance' => MoneyMath::round(bcsub((string) $anchor->balance, $amountsThroughAnchor, 10)),
            'opening_balance_origin' => 'imported',
        ]);
    }
}
