<?php

namespace App\Services\Pixelforge;

use RuntimeException;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /** Temporary problems: nothing was created, so the same request can be retried. */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true)
            || $this->status >= 500;
    }
}
