<?php

namespace App\Actions;

use App\Jobs\ConvertReportingAmounts;
use App\Models\BankAccount;
use App\Models\Posting;
use App\Support\MoneyMath;

final class CreateManualPosting
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(BankAccount $bankAccount, array $attributes): Posting
    {
        $accountAmount = MoneyMath::round((string) $attributes['amount']);

        // The entered amount is the account-currency movement; the reporting
        // pair follows the owner's reporting currency.
        $posting = new Posting([
            ...$attributes,
            'user_id' => $bankAccount->user_id,
            'original_amount' => $accountAmount,
            'original_currency' => $bankAccount->currency,
        ]);
        $posting->applyReportingAmount($bankAccount->user->reporting_currency ?? $bankAccount->currency);
        $bankAccount->postings()->save($posting);

        app(RecalculateAccountBalance::class)->handle($bankAccount, $accountAmount);

        if ($posting->amount === null && $posting->original_amount !== null) {
            ConvertReportingAmounts::dispatch($bankAccount->user_id)->afterCommit();
        }

        return $posting;
    }
}
