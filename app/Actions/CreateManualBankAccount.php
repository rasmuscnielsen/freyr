<?php

namespace App\Actions;

use App\Jobs\ConvertReportingAmounts;
use App\Models\BankAccount;
use App\Models\User;
use App\Support\MoneyMath;
use Illuminate\Support\Str;

final class CreateManualBankAccount
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $user, array $attributes): BankAccount
    {
        $openingBalance = MoneyMath::round((string) ($attributes['opening_balance'] ?? '0.00'));

        $account = $user->bankAccounts()->create([
            ...$attributes,
            'legacy_id' => BankAccount::MANUAL_LEGACY_ID_PREFIX.Str::uuid()->toString(),
            'opening_balance' => $openingBalance,
            'current_balance' => $openingBalance,
        ]);

        if ($user->reporting_currency !== null && $account->currency !== $user->reporting_currency) {
            ConvertReportingAmounts::dispatch($user->id)->afterCommit();
        }

        return $account;
    }
}
