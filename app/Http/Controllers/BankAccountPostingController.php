<?php

namespace App\Http\Controllers;

use App\Finance\AccountBalanceRecalculator;
use App\Http\Requests\StorePostingRequest;
use App\Http\Requests\UpdatePostingRequest;
use App\Models\BankAccount;
use App\Models\Posting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual posting CRUD for bank accounts without a linked bank connection.
 * Connected accounts receive their postings from sync and are read-only here.
 */
class BankAccountPostingController extends Controller
{
    public function __construct(private AccountBalanceRecalculator $balanceRecalculator) {}

    public function store(StorePostingRequest $request, BankAccount $bankAccount): RedirectResponse
    {
        $bankAccount->postings()->create([
            ...$request->validated(),
            'user_id' => $bankAccount->user_id,
            'currency' => $bankAccount->currency,
        ]);

        $this->balanceRecalculator->recalculate($bankAccount);

        return back();
    }

    public function update(UpdatePostingRequest $request, BankAccount $bankAccount, Posting $posting): RedirectResponse
    {
        $posting->update($request->validated());

        $this->balanceRecalculator->recalculate($bankAccount);

        return back();
    }

    public function destroy(Request $request, BankAccount $bankAccount, Posting $posting): RedirectResponse
    {
        abort_unless($request->user()?->is($bankAccount->user), 403);
        abort_if($bankAccount->hasBankConnection(), 403);

        $posting->delete();

        $this->balanceRecalculator->recalculate($bankAccount);

        return back();
    }
}
