<?php

namespace App\Finance\Fx;

use RuntimeException;

/**
 * A provider request outcome that must shape cooldown and retry behavior.
 * Kinds: rate_limited, access_denied, unavailable, unsupported.
 */
final class FxRequestException extends RuntimeException
{
    private function __construct(
        public readonly string $kind,
        public readonly ?int $retryAfterSeconds = null,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "FX request failed: {$kind}");
    }

    public static function rateLimited(?int $retryAfterSeconds): self
    {
        return new self('rate_limited', $retryAfterSeconds);
    }

    public static function accessDenied(): self
    {
        return new self('access_denied');
    }

    public static function unavailable(string $message): self
    {
        return new self('unavailable', null, $message);
    }

    public static function unsupported(string $message): self
    {
        return new self('unsupported', null, $message);
    }

    /**
     * How long the installation-wide cooldown lasts for this failure.
     */
    public function cooldownSeconds(): int
    {
        return match ($this->kind) {
            'rate_limited' => $this->retryAfterSeconds ?? 15 * 60,
            'access_denied' => 15 * 60,
            'unavailable' => 5 * 60,
            default => 0,
        };
    }
}
