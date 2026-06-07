<?php

namespace App\Http\Controllers;

use App\Http\Requests\LinkBankConnectionAccountRequest;
use App\Jobs\SyncBankConnectionAccountJob;
use App\Models\BankConnectionAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BankConnectionAccountController extends Controller
{
    public function update(LinkBankConnectionAccountRequest $request, BankConnectionAccount $bankConnectionAccount): RedirectResponse
    {
        $bankConnectionAccount->loadMissing('bankConnection');
        abort_unless($request->user()?->is($bankConnectionAccount->bankConnection->user), 403);

        $validated = $request->validated();

        if ($validated['mode'] === 'ignore') {
            $bankConnectionAccount->update([
                'status' => BankConnectionAccount::STATUS_IGNORED,
                'bank_account_id' => null,
            ]);

            return back();
        }

        $bankAccountId = $validated['bank_account_id'] ?? null;

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

        return back();
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
