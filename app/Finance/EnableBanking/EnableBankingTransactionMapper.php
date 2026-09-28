<?php

namespace App\Finance\EnableBanking;

use App\Finance\MoneyMath;
use App\Models\BankConnectionAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class EnableBankingTransactionMapper
{
    /**
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    public function postingAttributes(BankConnectionAccount $account, array $transaction): array
    {
        $transactionId = $this->transactionId($transaction);
        $accountAmount = $this->signedAmount($transaction);
        // transaction_amount is denominated in the account currency, so it
        // fills the original (account) pair. The reporting amount is copied
        // immediately only when the account already moves in the owner's
        // reporting currency; foreign postings stay null for the conversion
        // job.
        $accountCurrency = (string) data_get($transaction, 'transaction_amount.currency', $account->currency);
        $reportingCurrency = $account->bankConnection->user->reporting_currency ?? $accountCurrency;

        // A running balance only counts when it is denominated in the
        // account currency — a foreign figure must not anchor the chain.
        $balanceCurrency = data_get($transaction, 'balance_after_transaction.currency');
        $balance = $balanceCurrency === null || $balanceCurrency === $account->currency
            ? $this->decimal(data_get($transaction, 'balance_after_transaction.amount'))
            : null;

        return [
            'user_id' => $account->bankConnection->user_id,
            'bank_account_id' => $account->bank_account_id,
            'source' => BankConnectionAccount::SOURCE_ENABLE_BANKING,
            'date' => $this->transactionDate($transaction)->toDateString(),
            'description' => $this->description($transaction),
            'original_description' => $this->description($transaction),
            'amount' => $accountCurrency === $reportingCurrency ? $accountAmount : null,
            'original_amount' => $accountAmount,
            'balance' => $balance,
            'external_posting_id' => $transactionId,
            'currency' => $reportingCurrency,
            'original_currency' => $accountCurrency,
            'state' => $this->nullableString($transaction['status'] ?? null),
            'extra_data' => $transaction,
        ];
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function transactionDate(array $transaction): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $transaction['booking_date']);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function transactionId(array $transaction): string
    {
        return (string) (filled($transaction['entry_reference'] ?? null)
            ? $transaction['entry_reference']
            : ($transaction['transaction_id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function signedAmount(array $transaction): string
    {
        $raw = trim((string) data_get($transaction, 'transaction_amount.amount', '0'));
        $amount = is_numeric($raw) ? $raw : '0';
        $amount = bccomp($amount, '0', 10) < 0 ? bcmul($amount, '-1', 10) : $amount;

        if (($transaction['credit_debit_indicator'] ?? '') === 'DBIT') {
            $amount = bcmul($amount, '-1', 10);
        }

        return MoneyMath::round($amount);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function description(array $transaction): string
    {
        $remittance = Arr::wrap($transaction['remittance_information'] ?? []);
        $description = collect($remittance)
            ->filter(fn (mixed $value): bool => filled($value))
            ->join(' ');

        return $description !== '' ? $description : ($this->nullableString($transaction['entry_reference'] ?? null) ?? 'Bank transaction');
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return MoneyMath::round((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
