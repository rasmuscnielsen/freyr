<?php

namespace App\Finance\EnableBanking;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class EnableBankingClient
{
    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(private readonly ?array $config = null) {}

    /**
     * @return array<string, mixed>
     */
    public function aspsps(?string $country = null, ?string $psuType = null, ?string $service = 'AIS'): array
    {
        return $this->request()
            ->get('/aspsps', array_filter([
                'country' => $country,
                'psu_type' => $psuType,
                'service' => $service,
            ]))
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function startAuthorization(string $redirectUrl, string $state, ?string $aspspName = null, ?string $aspspCountry = null): array
    {
        return $this->request()
            ->post('/auth', [
                'access' => [
                    'valid_until' => CarbonImmutable::now()
                        ->addDays((int) $this->config('access_days', 90))
                        ->toIso8601String(),
                ],
                'aspsp' => [
                    'name' => $aspspName ?: $this->config('aspsp_name'),
                    'country' => $aspspCountry ?: $this->config('aspsp_country'),
                ],
                'state' => $state,
                'redirect_url' => $redirectUrl,
                'psu_type' => $this->config('psu_type', 'personal'),
                'language' => $this->config('language', 'en'),
            ])
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchSessionData(string $code, CarbonInterface|string|null $dateFrom = null): array
    {
        $session = $this->createSession($code);
        $accounts = Arr::wrap($session['accounts'] ?? []);
        $dateFrom = $this->transactionDateFrom($dateFrom);

        $accountData = collect($accounts)
            ->map(function (array $account) use ($dateFrom): array {
                $accountUid = (string) ($account['uid'] ?? '');

                if ($accountUid === '') {
                    return [
                        'account' => $account,
                        'balances' => [],
                        'transactions' => [],
                        'error' => 'Account is missing uid.',
                    ];
                }

                return [
                    'account' => $account,
                    'balances' => $this->balances($accountUid),
                    'transactions' => $this->transactions($accountUid, $dateFrom),
                ];
            })
            ->values()
            ->all();

        return [
            'session' => $session,
            'accounts' => $accountData,
            'transaction_date_from' => $dateFrom->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(string $code): array
    {
        return $this->request()
            ->post('/sessions', ['code' => $code])
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function session(string $sessionId): array
    {
        return $this->request()
            ->get(sprintf('/sessions/%s', $sessionId))
            ->throw()
            ->json();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function balances(string $accountUid): array
    {
        return Arr::wrap($this->request()
            ->get(sprintf('/accounts/%s/balances', $accountUid))
            ->throw()
            ->json('balances'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transactions(string $accountUid, CarbonInterface $dateFrom): array
    {
        $transactions = [];
        $continuationKey = null;

        do {
            $response = $this->request()
                ->get(sprintf('/accounts/%s/transactions', $accountUid), array_filter([
                    'date_from' => $dateFrom->toDateString(),
                    'continuation_key' => $continuationKey,
                ]))
                ->throw()
                ->json();

            $transactions = [
                ...$transactions,
                ...Arr::wrap($response['transactions'] ?? []),
            ];

            $continuationKey = $response['continuation_key'] ?? null;
        } while (filled($continuationKey));

        return $transactions;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->config('base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($this->jwt())
            ->connectTimeout(5)
            ->timeout(20);
    }

    private function jwt(): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'RS256',
            'kid' => $this->config('app_id'),
        ];
        $now = CarbonImmutable::now()->timestamp;
        $payload = [
            'iss' => 'enablebanking.com',
            'aud' => 'api.enablebanking.com',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $segments = [
            $this->base64UrlEncode($this->jsonEncode($header)),
            $this->base64UrlEncode($this->jsonEncode($payload)),
        ];

        if (! openssl_sign(implode('.', $segments), $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign Enable Banking JWT.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function privateKey(): string
    {
        $privateKey = (string) $this->config('private_key');

        if (Str::contains($privateKey, '-----BEGIN')) {
            return $privateKey;
        }

        if (is_file($privateKey)) {
            return (string) file_get_contents($privateKey);
        }

        throw new RuntimeException('Enable Banking private key is not configured.');
    }

    private function transactionDateFrom(CarbonInterface|string|null $dateFrom): CarbonInterface
    {
        if ($dateFrom instanceof CarbonInterface) {
            return $dateFrom;
        }

        if (is_string($dateFrom)) {
            return CarbonImmutable::parse($dateFrom);
        }

        return CarbonImmutable::now()->subDays((int) $this->config('transaction_days', 30));
    }

    private function config(string $key, mixed $default = null): mixed
    {
        $value = ($this->config ?? config('services.enable_banking'))[$key] ?? $default;

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Enable Banking config value [%s] is missing.', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function jsonEncode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Enable Banking JWT.', previous: $exception);
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
