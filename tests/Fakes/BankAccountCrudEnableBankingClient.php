<?php

namespace Tests\Fakes;

use App\Services\EnableBanking\Client;

class BankAccountCrudEnableBankingClient extends Client
{
    /**
     * @return array<string, mixed>
     */
    public function aspsps(?string $country = null, ?string $psuType = null, ?string $service = 'AIS'): array
    {
        return [
            'aspsps' => [
                ['name' => 'Example Bank', 'country' => 'DK'],
                ['name' => 'Demo Bank', 'country' => 'DK'],
            ],
        ];
    }
}
