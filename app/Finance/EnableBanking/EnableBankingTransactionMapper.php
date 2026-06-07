<?php

namespace App\Finance\EnableBanking;

use App\Models\BankConnectionAccount;
use App\Models\Posting;
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
        $amount = $this->signedAmount($transaction);
        $currency = (string) data_get($transaction, 'transaction_amount.currency', $account->currency);

        return [
            'user_id' => $account->bankConnection->user_id,
            'bank_account_id' => $account->bank_account_id,
            'legacy_id' => $this->legacyId($account, $transactionId),
            'source' => BankConnectionAccount::SOURCE_ENABLE_BANKING,
            'date' => $this->transactionDate($transaction)->toDateString(),
            'description' => $this->description($transaction),
            'original_description' => $this->description($transaction),
            'amount' => $amount,
            'original_amount' => $amount,
            'balance' => $this->decimal(data_get($transaction, 'balance_after_transaction.amount')),
            'external_posting_id' => $transactionId,
            'external_account_uid_hash' => $account->external_account_uid_hash,
            'category_type_code' => $amount < 0 ? Posting::CATEGORY_TYPE_EXPENSE : Posting::CATEGORY_TYPE_INCOME,
            'currency' => $currency,
            'original_currency' => $currency,
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
        return (string) ($transaction['transaction_id'] ?? $transaction['entry_reference'] ?? '');
    }

    private function legacyId(BankConnectionAccount $account, string $transactionId): string
    {
        return sprintf('enable-banking:%s:%s', $account->external_account_uid_hash, $transactionId);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function signedAmount(array $transaction): string
    {
        $amount = abs((float) data_get($transaction, 'transaction_amount.amount', 0));
        $indicator = (string) ($transaction['credit_debit_indicator'] ?? '');

        if ($indicator === 'DBIT') {
            $amount *= -1;
        }

        return number_format($amount, 2, '.', '');
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
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
