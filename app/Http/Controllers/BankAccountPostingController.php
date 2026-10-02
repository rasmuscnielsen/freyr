<?php

namespace App\Http\Controllers;

use App\Actions\RecalculateAccountBalance;
use App\Http\Requests\StorePostingRequest;
use App\Http\Requests\UpdatePostingRequest;
use App\Jobs\ConvertReportingAmounts;
use App\Models\BankAccount;
use App\Models\Posting;
use App\Support\MoneyMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual posting CRUD for bank accounts without a linked bank connection.
 * Connected accounts receive their postings from sync and are read-only here.
 */
class BankAccountPostingController extends Controller
{
    public function __construct(private RecalculateAccountBalance $balanceRecalculator) {}

    public function store(StorePostingRequest $request, BankAccount $bankAccount): RedirectResponse
    {
        $validated = $request->validated();
        $accountAmount = MoneyMath::round((string) $validated['amount']);

        // The entered amount is the account-currency movement; the reporting
        // pair follows the owner's reporting currency.
        $posting = new Posting([
            ...$validated,
            'user_id' => $bankAccount->user_id,
            'original_amount' => $accountAmount,
            'original_currency' => $bankAccount->currency,
        ]);
        $posting->applyReportingAmount($bankAccount->user->reporting_currency ?? $bankAccount->currency);
        $bankAccount->postings()->save($posting);

        $this->balanceRecalculator->handle($bankAccount, $accountAmount);

        if ($posting->amount === null && $posting->original_amount !== null) {
            ConvertReportingAmounts::dispatch($bankAccount->user_id)->afterCommit();
        }

        return back();
    }

    public function update(UpdatePostingRequest $request, BankAccount $bankAccount, Posting $posting): RedirectResponse
    {
        $validated = $request->validated();

        if (array_key_exists('amount', $validated)) {
            $validated['original_amount'] = MoneyMath::round((string) $validated['amount']);
            $validated['original_currency'] = $bankAccount->currency;
        }

        $monetaryChanged = array_key_exists('amount', $validated) || array_key_exists('date', $validated);
        $delta = array_key_exists('amount', $validated)
            ? bcsub($validated['original_amount'], (string) ($posting->original_amount ?? '0'), 10)
            : '0';

        $posting->fill($validated);

        // Amount or date edits invalidate a foreign-currency reporting amount;
        // the conversion job refills it at the new booking date.
        if ($monetaryChanged) {
            $posting->applyReportingAmount($bankAccount->user->reporting_currency ?? $bankAccount->currency);
        }

        $posting->save();

        if ($monetaryChanged) {
            $this->balanceRecalculator->handle($bankAccount, $delta);
        }

        if ($monetaryChanged && $posting->amount === null && $posting->original_amount !== null) {
            ConvertReportingAmounts::dispatch($bankAccount->user_id)->afterCommit();
        }

        return back();
    }

    public function destroy(Request $request, BankAccount $bankAccount, Posting $posting): RedirectResponse
    {
        abort_unless($request->user()?->is($bankAccount->user), 403);
        abort_if($bankAccount->hasBankConnection(), 403);

        $delta = bcmul((string) ($posting->original_amount ?? '0'), '-1', 10);

        $posting->delete();

        $this->balanceRecalculator->handle($bankAccount, $delta);

        return back();
    }
}
