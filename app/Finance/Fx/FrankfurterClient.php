<?php

namespace App\Finance\Fx;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Frankfurter v2, ECB provider: published reference rates, no key required.
 * One base unit equals `rate` quote units. Returns only publication dates —
 * non-publication days are simply absent from the response.
 */
class FrankfurterClient
{
    public const string PROVIDER = 'frankfurter-ecb';

    private const string BASE_URL = 'https://api.frankfurter.dev';

    private const int TIMEOUT_SECONDS = 5;

    /**
     * Fetch published rates for $base against $quotes over [$from, $to].
     *
     * @param  list<string>  $quotes
     * @return list<array{date:string, base:string, quote:string, rate:numeric-string}>
     *
     * @throws FxRequestException
     */
    public function rates(string $base, array $quotes, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(self::BASE_URL.'/v2/providers/ecb/rates', [
                    'base' => $base,
                    'quotes' => implode(',', $quotes),
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ]);
        } catch (ConnectionException $exception) {
            throw FxRequestException::unavailable($exception->getMessage());
        }

        if ($response->successful()) {
            return collect(Arr::wrap($response->json()))
                ->filter(fn (mixed $row): bool => is_array($row)
                    && isset($row['date'], $row['base'], $row['quote'], $row['rate']))
                ->map(fn (array $row): array => [
                    'date' => (string) $row['date'],
                    'base' => (string) $row['base'],
                    'quote' => (string) $row['quote'],
                    'rate' => (string) $row['rate'],
                ])
                ->values()
                ->all();
        }

        throw match ($response->status()) {
            429 => FxRequestException::rateLimited($this->retryAfterSeconds($response->header('Retry-After'))),
            401, 403 => FxRequestException::accessDenied(),
            422 => FxRequestException::unsupported((string) $response->json('message', 'unsupported pair')),
            default => FxRequestException::unavailable("HTTP {$response->status()}"),
        };
    }

    /**
     * @param  array<int|string, string>|string|null  $header
     */
    private function retryAfterSeconds(array|string|null $header): ?int
    {
        $value = is_array($header) ? ($header[0] ?? null) : $header;

        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}
