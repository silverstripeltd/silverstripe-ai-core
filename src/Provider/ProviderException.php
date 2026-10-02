<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider;

use RuntimeException;
use Throwable;

/**
 * Raised by a chat provider when a call cannot complete.
 *
 * Transient failures (rate limits, server errors, network faults) may be retried later by the
 * caller. Blocking failures (bad credentials, missing key, unknown provider) need a person to
 * fix configuration first. Messages never contain credentials.
 *
 * A rate limited failure may carry the provider's hint of how long to wait before trying
 * again, and whether the allowance that ran out is a daily one, which waiting a few seconds
 * does not refill.
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $transient = false,
        private readonly bool $blocking = false,
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?int $retryAfterSeconds = null,
        private readonly bool $dailyQuotaExhausted = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param int|null $retryAfterSeconds The provider's hint of when to try again, when it gave one
     * @param bool $dailyQuotaExhausted True when a per day allowance ran out
     */
    public static function transient(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        ?int $retryAfterSeconds = null,
        bool $dailyQuotaExhausted = false,
    ): self {
        return new self($message, true, false, $code, $previous, $retryAfterSeconds, $dailyQuotaExhausted);
    }

    public static function blocking(string $message, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message, false, true, $code, $previous);
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }

    public function isBlocking(): bool
    {
        return $this->blocking;
    }

    /**
     * Whole seconds the provider asked the caller to wait before trying again; null when it
     * gave no hint.
     */
    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * True when the failure is a per day allowance that has run out, such as a free tier's
     * daily request limit, rather than a short busy period.
     */
    public function isDailyQuotaExhausted(): bool
    {
        return $this->dailyQuotaExhausted;
    }
}
