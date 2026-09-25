<?php

namespace App\Http\Controllers;

use App\Finance\BankConnectionAccountLinker;
use App\Http\Requests\LinkBankConnectionAccountRequest;
use App\Jobs\SyncBankConnectionAccountJob;
use App\Models\BankConnectionAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BankConnectionAccountController extends Controller
{
    public function update(LinkBankConnectionAccountRequest $request, BankConnectionAccount $bankConnectionAccount, BankConnectionAccountLinker $linker): RedirectResponse
    {
        $bankConnectionAccount->loadMissing('bankConnection');
        abort_unless($request->user()?->is($bankConnectionAccount->bankConnection->user), 403);

        $validated = $request->validated();

        if ($validated['mode'] === 'ignore') {
            $linker->ignore($bankConnectionAccount);

            return back();
        }

        if ($validated['mode'] === 'existing') {
            $linker->linkExisting(
                $bankConnectionAccount,
                $request->user()->bankAccounts()->findOrFail($validated['bank_account_id']),
            );

            return back();
        }

        $linker->createAndLink($bankConnectionAccount, $validated['name']);

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
