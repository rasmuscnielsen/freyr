<?php

namespace Tests\Fakes;

use App\Finance\EnableBanking\EnableBankingClient;
use Carbon\CarbonInterface;

class FakeEnableBankingClient extends EnableBankingClient
{
    /**
     * @var list<string>
     */
    public array $transactionDateFroms = [];

    /**
     * @param  array<string, mixed>  $session
     * @param  list<array<string, mixed>>  $balances
     * @param  list<array<string, mixed>>  $transactions
     */
    public function __construct(
        private readonly array $session = [],
        private readonly array $balances = [],
        private readonly array $transactions = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startAuthorization(string $redirectUrl, string $state, ?string $aspspName = null, ?string $aspspCountry = null): array
    {
        return [
            'authorization_id' => '2c829ff8-ed4e-4fec-a476-19fded89011a',
            'url' => 'https://enablebanking.test/login',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aspsps(?string $country = null, ?string $psuType = null, ?string $service = 'AIS'): array
    {
        return [
            'aspsps' => [
                ['name' => 'Example Bank', 'country' => 'DK'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(string $code): array
    {
        return $this->session;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function balances(string $accountUid): array
    {
        return $this->balances;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transactions(string $accountUid, CarbonInterface $dateFrom): array
    {
        $this->transactionDateFroms[] = $dateFrom->toDateString();

        return $this->transactions;
    }
}
