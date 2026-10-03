<?php

namespace App\Concerns;

use App\Enums\SyncHistory;
use App\Jobs\SyncBankConnectionAccount;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use Carbon\CarbonImmutable;

trait LinksBankConnectionAccounts
{
    protected function linkRemoteAccount(BankConnectionAccount $remote, int $bankAccountId): void
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
        SyncBankConnectionAccount::dispatch($remote)->afterCommit();
    }

    /**
     * The import cutoff keeps the first sync from importing transactions the
     * user does not want (their chosen history depth) or must not duplicate:
     * postings that already arrived by other means (e.g. a Spiir import or
     * manual entry) cannot be matched by external id, so only transactions
     * newer than the stored history may sync.
     */
    protected function applyImportCutoff(BankAccount $bankAccount, SyncHistory $history): void
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
