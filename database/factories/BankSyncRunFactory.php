<?php

namespace Database\Factories;

use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankSyncRun>
 */
class BankSyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_connection_id' => BankConnection::factory(),
            'status' => BankSyncRun::STATUS_SUCCEEDED,
            'date_from' => now()->startOfYear()->toDateString(),
            'date_to' => now()->toDateString(),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'new_postings_count' => 1,
            'updated_postings_count' => 0,
            'duplicate_postings_count' => 0,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BankSyncRun::STATUS_FAILED,
            'finished_at' => now(),
            'error_code' => 'CONSENT_EXPIRED',
            'error_message' => 'The bank connection needs renewal.',
        ]);
    }

    public function forConnectionAccount(BankConnectionAccount $account): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $account->user_id,
            'bank_connection_id' => $account->bank_connection_id,
            'bank_connection_account_id' => $account->id,
            'bank_account_id' => $account->bank_account_id,
        ]);
    }
}
