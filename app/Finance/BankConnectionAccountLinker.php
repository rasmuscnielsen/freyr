<?php

namespace App\Finance;

use App\Enums\SyncHistory;
use App\Jobs\SyncBankConnectionAccountJob;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BankConnectionAccountLinker
{
    public function linkExisting(BankConnectionAccount $remote, BankAccount $local, SyncHistory $history = SyncHistory::Max): void
    {
        // The feed's account currency must match the local account's so its
        // movements and reported balance keep one currency.
        if ($remote->currency !== $local->currency) {
            throw ValidationException::withMessages([
                'bank_account_id' => "The bank feed reports {$remote->currency} but {$local->name} is a {$local->currency} account.",
            ]);
        }

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

        // Keep the provider snapshot; only calculate a manual total after the
        // user sets an opening balance.
        if ($detachedAccount !== null && ! $detachedAccount->bankConnectionAccounts()->exists()) {
            $detachedAccount->update(['opening_balance' => null]);
        }
    }

    private function link(BankConnectionAccount $remote, int $bankAccountId): void
    {
        $remote->update([
            'status' => BankConnectionAccount::STATUS_LINKED,
            'bank_account_id' => $bankAccountId,
        ]);

        $remote->loadMissing('bankConnection');

        // An archived connection is parked: keep the link, but leave syncing
        // for when it is re-opened — a queued job would only record a failed
        // run.
        if ($remote->bankConnection->status === BankConnection::STATUS_ARCHIVED) {
            return;
        }

        // Dispatch after commit: batch mapping wraps the link in a
        // transaction, and a worker must never see a half-written link.
        SyncBankConnectionAccountJob::dispatch($remote)->afterCommit();
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
