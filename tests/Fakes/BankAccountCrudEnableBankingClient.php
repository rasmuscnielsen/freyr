<?php

namespace Tests\Fakes;

use App\Finance\EnableBanking\EnableBankingClient;

class BankAccountCrudEnableBankingClient extends EnableBankingClient
{
    /**
     * @return array<string, mixed>
     */
    public function aspsps(?string $country = null, ?string $psuType = null, ?string $service = 'AIS'): array
    {
        return [
            'aspsps' => [
                ['name' => 'Nykredit Bank', 'country' => 'DK'],
                ['name' => 'Lunar Bank', 'country' => 'DK'],
            ],
        ];
    }
}
