<?php

namespace App\Http\Controllers;

use App\Actions\CreateLinkedBankAccount;
use App\Actions\IgnoreBankConnectionAccount;
use App\Actions\LinkBankConnectionAccount;
use App\Enums\SyncHistory;
use App\Http\Requests\LinkBankConnectionAccountRequest;
use App\Jobs\SyncBankConnectionAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BankConnectionAccountController extends Controller
{
    public function update(
        LinkBankConnectionAccountRequest $request,
        BankConnectionAccount $bankConnectionAccount,
        LinkBankConnectionAccount $link,
        CreateLinkedBankAccount $create,
        IgnoreBankConnectionAccount $ignore,
    ): RedirectResponse {
        $bankConnectionAccount->loadMissing('bankConnection');
        abort_unless($request->user()?->is($bankConnectionAccount->bankConnection->user), 403);

        $validated = $request->validated();

        if ($validated['mode'] === 'ignore') {
            $ignore->handle($bankConnectionAccount);

            return back();
        }

        $history = isset($validated['history']) ? SyncHistory::from($validated['history']) : SyncHistory::Max;

        if ($validated['mode'] === 'existing') {
            $link->handle(
                $bankConnectionAccount,
                $request->user()->bankAccounts()->findOrFail($validated['bank_account_id']),
                $history,
            );

            return back();
        }

        $create->handle($bankConnectionAccount, $validated['name'], $history);

        return back();
    }

    public function sync(Request $request, BankConnectionAccount $bankConnectionAccount): RedirectResponse
    {
        $bankConnectionAccount->loadMissing('bankConnection');
        abort_unless($request->user()?->is($bankConnectionAccount->bankConnection->user), 403);
        abort_unless($bankConnectionAccount->bankConnection->status !== BankConnection::STATUS_ARCHIVED, 422);
        abort_unless($bankConnectionAccount->bank_account_id !== null, 422);
        abort_unless($bankConnectionAccount->bankAccount?->is_active ?? false, 422);

        SyncBankConnectionAccount::dispatch($bankConnectionAccount);

        return back();
    }
}
