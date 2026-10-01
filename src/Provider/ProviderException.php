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
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $transient = false,
        private readonly bool $blocking = false,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function transient(string $message, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message, true, false, $code, $previous);
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
}
