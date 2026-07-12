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

            DB::update(<<<'SQL'
                UPDATE postings
                SET balance = chain.running_balance
                FROM (
                    SELECT id, CAST(? AS NUMERIC) + SUM(amount) OVER (ORDER BY date, id) AS running_balance
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

        $amountsThroughAnchor = (float) $bankAccount->postings()
            ->where(function ($query) use ($anchor): void {
                $query
                    ->where('date', '<', $anchor->date)
                    ->orWhere(fn ($nested) => $nested->where('date', $anchor->date)->where('id', '<=', $anchor->id));
            })
            ->sum('amount');

        $bankAccount->update([
            'opening_balance' => number_format((float) $anchor->balance - $amountsThroughAnchor, 2, '.', ''),
        ]);
    }
}
