<?php

namespace App\Http\Controllers;

use App\Finance\AccountBalanceRecalculator;
use App\Http\Requests\LinkBankConnectionAccountRequest;
use App\Jobs\SyncBankConnectionAccountJob;
use App\Models\BankAccount;
use App\Models\BankConnectionAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BankConnectionAccountController extends Controller
{
    public function update(LinkBankConnectionAccountRequest $request, BankConnectionAccount $bankConnectionAccount, AccountBalanceRecalculator $balanceRecalculator): RedirectResponse
    {
        $bankConnectionAccount->loadMissing('bankConnection');
        abort_unless($request->user()?->is($bankConnectionAccount->bankConnection->user), 403);

        $validated = $request->validated();

        if ($validated['mode'] === 'ignore') {
            $detachedAccount = $bankConnectionAccount->bankAccount;

            $bankConnectionAccount->update([
                'status' => BankConnectionAccount::STATUS_IGNORED,
                'bank_account_id' => null,
            ]);

            // A detached account without any remaining connection is manual
            // from here on: anchor its opening balance while the synced
            // running balances are still authoritative, then derive its chain.
            if ($detachedAccount !== null && ! $detachedAccount->hasBankConnection()) {
                $balanceRecalculator->seedOpeningBalanceFromImportedChain($detachedAccount);
                $balanceRecalculator->recalculate($detachedAccount);
            }

            return back();
        }

        $bankAccountId = $validated['bank_account_id'] ?? null;

        if ($validated['mode'] === 'existing') {
            $this->preventHistoricalReimport(
                $request->user()->bankAccounts()->findOrFail($bankAccountId),
            );
        }

        if ($validated['mode'] === 'create') {
            $bankAccount = $request->user()->bankAccounts()->create([
                'legacy_id' => 'enable-banking-'.Str::uuid()->toString(),
                'name' => $validated['name'],
                'bank_key' => 'enable_banking_'.$bankConnectionAccount->bankConnection->aspsp_country,
                'bank_name' => $bankConnectionAccount->bankConnection->aspsp_name,
                'currency' => $bankConnectionAccount->currency,
                'account_type' => $bankConnectionAccount->cash_account_type,
                'account_number' => $bankConnectionAccount->account_number,
                'connection_type' => 'EnableBanking',
            ]);
            $bankAccountId = $bankAccount->id;
        }

        $bankConnectionAccount->update([
            'status' => BankConnectionAccount::STATUS_LINKED,
            'bank_account_id' => $bankAccountId,
        ]);

        SyncBankConnectionAccountJob::dispatch($bankConnectionAccount);

        return back();
    }

    /**
     * When linking to an account that already holds postings (e.g. from a
     * Spiir import or manual entry), only transactions newer than the stored
     * history should sync, so the first sync does not duplicate postings
     * that arrived by other means and cannot be matched by external id.
     */
    private function preventHistoricalReimport(BankAccount $bankAccount): void
    {
        if ($bankAccount->ignore_postings_before !== null) {
            return;
        }

        $latestPostingDate = $bankAccount->postings()->max('date');

        if ($latestPostingDate === null) {
            return;
        }

        $bankAccount->update([
            'ignore_postings_before' => CarbonImmutable::parse($latestPostingDate)->addDay(),
        ]);
    }

    public function sync(Request $request, BankConnectionAccount $bankConnectionAccount): RedirectResponse
    {
        $bankConnectionAccount->loadMissing('bankConnection');
        abort_unless($request->user()?->is($bankConnectionAccount->bankConnection->user), 403);
        abort_unless($bankConnectionAccount->bank_account_id !== null, 422);

        SyncBankConnectionAccountJob::dispatch($bankConnectionAccount);

        return back();
    }
}
