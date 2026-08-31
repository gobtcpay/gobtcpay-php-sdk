<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Exception;

use Throwable;

/**
 * Thrown when the API responds with an error envelope or a non-2xx status.
 *
 * Prefer catching one of the subclasses — {@see AuthException},
 * {@see ValidationException}, {@see NotFoundException}, {@see RateLimitException},
 * {@see ServerException} — and use this one as the catch-all.
 */
class ApiException extends GoBTCPayException
{
    /**
     * @param int $httpStatus HTTP status code of the response.
     * @param array<string, mixed>|null $body Structured error body returned by the API, if any.
     * @param string|null $requestId Value of the `X-Request-Id` response header. Quote
     *                               it in support requests — it locates the request in our logs.
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?array $body = null,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Machine-readable error type from the API body (`body.data.type`), when present. */
    public function type(): ?string
    {
        $data = $this->body['data'] ?? null;
        if (is_array($data) && isset($data['type']) && is_string($data['type'])) {
            return $data['type'];
        }

        return null;
    }
}
