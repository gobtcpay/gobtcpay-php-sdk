<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Exception;

use Throwable;

/** 429 — too many requests. {@see retryAfterMs} is set when the API said so. */
class RateLimitException extends ApiException
{
    /**
     * @param array<string, mixed>|null $body
     * @param int|null $retryAfterMs Suggested wait before retrying, in milliseconds.
     */
    public function __construct(
        string $message,
        int $httpStatus,
        ?array $body = null,
        ?string $requestId = null,
        public readonly ?int $retryAfterMs = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $body, $requestId, $previous);
    }
}
