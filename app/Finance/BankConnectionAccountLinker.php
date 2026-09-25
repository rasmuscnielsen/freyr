<?php

namespace App\Finance;

use App\Enums\SyncHistory;
use App\Jobs\SyncBankConnectionAccountJob;
use App\Models\BankAccount;
use App\Models\BankConnectionAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class BankConnectionAccountLinker
{
    public function __construct(
        private readonly AccountBalanceRecalculator $balanceRecalculator,
    ) {}

    public function linkExisting(BankConnectionAccount $remote, BankAccount $local, SyncHistory $history = SyncHistory::Max): void
    {
        $this->applyImportCutoff($local, $history);

        $this->link($remote, $local->id);
    }

    public function createAndLink(BankConnectionAccount $remote, string $name, SyncHistory $history = SyncHistory::Max): BankAccount
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
            'connection_type' => 'EnableBanking',
        ]);

        $this->link($remote, $bankAccount->id);

        return $bankAccount;
    }

    public function ignore(BankConnectionAccount $remote): void
    {
        $detachedAccount = $remote->bankAccount;

        $remote->update([
            'status' => BankConnectionAccount::STATUS_IGNORED,
            'bank_account_id' => null,
        ]);

        // A detached account without any remaining connection is manual
        // from here on: anchor its opening balance while the synced
        // running balances are still authoritative, then derive its chain.
        if ($detachedAccount !== null && ! $detachedAccount->hasBankConnection()) {
            $this->balanceRecalculator->seedOpeningBalanceFromImportedChain($detachedAccount);
            $this->balanceRecalculator->recalculate($detachedAccount);
        }
    }

    private function link(BankConnectionAccount $remote, int $bankAccountId): void
    {
        $remote->update([
            'status' => BankConnectionAccount::STATUS_LINKED,
            'bank_account_id' => $bankAccountId,
        ]);

        SyncBankConnectionAccountJob::dispatch($remote);
    }

    /**
     * The import cutoff keeps the first sync from importing transactions the
     * user does not want (their chosen history depth) or must not duplicate:
     * postings that already arrived by other means (e.g. a Spiir import or
     * manual entry) cannot be matched by external id, so only transactions
     * newer than the stored history may sync.
     */
    private function applyImportCutoff(BankAccount $bankAccount, SyncHistory $history): void
    {
        if ($bankAccount->ignore_postings_before !== null) {
            return;
        }

        $cutoff = $history->cutoffDate();

        $latestPostingDate = $bankAccount->postings()->max('date');

        if ($latestPostingDate !== null) {
            $reimportCutoff = CarbonImmutable::parse($latestPostingDate)->addDay();

            if ($cutoff === null || $reimportCutoff->gt($cutoff)) {
                $cutoff = $reimportCutoff;
            }
        }

        if ($cutoff !== null) {
            $bankAccount->update(['ignore_postings_before' => $cutoff]);
        }
    }
}
