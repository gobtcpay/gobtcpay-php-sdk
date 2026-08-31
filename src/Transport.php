<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk;

use GoBTCPay\PosApiSdk\Dto\ApiEnvelope;
use GoBTCPay\PosApiSdk\Exception\ApiException;
use GoBTCPay\PosApiSdk\Exception\AuthException;
use GoBTCPay\PosApiSdk\Exception\NetworkException;
use GoBTCPay\PosApiSdk\Exception\NotFoundException;
use GoBTCPay\PosApiSdk\Exception\RateLimitException;
use GoBTCPay\PosApiSdk\Exception\ServerException;
use GoBTCPay\PosApiSdk\Exception\ValidationException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Low-level transport: authenticates a request, POSTs it, and unwraps the
 * `{ result: { success } }` envelope. Throws a typed subclass of
 * {@see ApiException} on an error envelope, or {@see NetworkException} when no
 * response arrived.
 *
 * Built on PSR-18 (HTTP client) + PSR-17 (request/stream factories) so it works
 * with any compliant stack. When none is supplied it auto-discovers Guzzle.
 */
final class Transport
{
    public const AUTH_HMAC = 'hmac';
    public const AUTH_BEARER = 'bearer';

    private const REQUEST_ID_HEADER = 'x-request-id';
    private const BASE_BACKOFF_MS = 250;
    private const MAX_BACKOFF_MS = 4000;

    private readonly string $baseUrl;

    /**
     * @param string $authStrategy self::AUTH_HMAC or self::AUTH_BEARER
     * @param string $apiKey Terminal HMAC key (hmac) or merchant secret (bearer)
     * @param int $timeoutMs Per-request timeout in milliseconds
     * @param int $maxRetries Retries after the first attempt (0 disables)
     * @param string $userAgent Value for the User-Agent header
     * @param (callable(array<string, mixed>): void)|null $onEvent Called once per attempt; never receives secrets
     */
    private readonly ?\Closure $onEvent;

    /** @phpstan-ignore constructor.unusedParameter (accepted for API parity; PSR-18 client owns the socket timeout) */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $authStrategy,
        private readonly string $apiKey,
        string $baseUrl,
        int $timeoutMs = 30_000,
        private readonly int $maxRetries = 0,
        private readonly string $userAgent = 'gobtcpay-pos-api-sdk-php',
        ?callable $onEvent = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->onEvent = $onEvent !== null ? \Closure::fromCallable($onEvent) : null;
    }

    /**
     * POST `$params` to `$path` and return the unwrapped `result.success` payload
     * as a decoded array.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $params, bool $retry = false): array
    {
        $maxAttempts = $retry ? $this->maxRetries + 1 : 1;
        $lastError = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $isLast = $attempt === $maxAttempts - 1;
            try {
                return $this->attempt($path, $params, $attempt);
            } catch (NetworkException | ApiException $err) {
                $lastError = $err;

                $retryable = $err instanceof NetworkException
                    || ($err instanceof ApiException && self::isRetryableStatus($err->httpStatus));
                if ($isLast || !$retryable) {
                    throw $err;
                }

                $hinted = $err instanceof RateLimitException ? $err->retryAfterMs : null;
                $this->sleepMs($hinted ?? self::backoffMs($attempt));
            }
        }

        throw $lastError ?? new NetworkException("Request to {$path} failed");
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function attempt(string $path, array $params, int $attempt): array
    {
        $startedAt = microtime(true);
        $url = $this->baseUrl . $path;

        $payload = $params;
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        if ($this->authStrategy === self::AUTH_HMAC) {
            $payload = Signing::signRequest($params, $this->apiKey);
        } else {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withBody($this->streamFactory->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $err) {
            $isTimeout = self::looksLikeTimeout($err->getMessage());
            $error = new NetworkException(
                $isTimeout
                    ? "Request to {$path} timed out"
                    : "Network error calling {$path}: " . $err->getMessage(),
                $isTimeout,
                $err,
            );
            $this->emit([
                'path' => $path,
                'attempt' => $attempt,
                'durationMs' => self::elapsedMs($startedAt),
                'error' => $error,
                'willRetry' => true,
            ]);
            throw $error;
        }

        return $this->handleResponse($path, $attempt, $startedAt, $response);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(
        string $path,
        int $attempt,
        float $startedAt,
        ResponseInterface $response,
    ): array {
        $status = $response->getStatusCode();
        $requestId = $response->getHeaderLine(self::REQUEST_ID_HEADER) ?: null;

        $text = (string) $response->getBody();
        $envelope = null;
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $envelope = ApiEnvelope::fromDecoded($decoded);
            }
        }

        if ($envelope !== null && $envelope->isSuccess) {
            $this->emit([
                'path' => $path,
                'attempt' => $attempt,
                'httpStatus' => $status,
                'requestId' => $requestId,
                'durationMs' => self::elapsedMs($startedAt),
                'willRetry' => false,
            ]);

            /** @var array<string, mixed> $success */
            $success = is_array($envelope->success) ? $envelope->success : [];

            return $success;
        }

        $failure = $envelope?->failure;
        $message = is_string($failure['message'] ?? null) && $failure['message'] !== ''
            ? $failure['message']
            : "Request to {$path} failed with status {$status}";

        $error = self::apiErrorFor(
            $status,
            $message,
            $failure,
            $requestId,
            self::parseRetryAfter($response->getHeaderLine('retry-after') ?: null),
        );

        $this->emit([
            'path' => $path,
            'attempt' => $attempt,
            'httpStatus' => $status,
            'requestId' => $requestId,
            'durationMs' => self::elapsedMs($startedAt),
            'error' => $error,
            'willRetry' => self::isRetryableStatus($status),
        ]);
        throw $error;
    }

    /**
     * Map an HTTP status onto the matching exception class. Unknown statuses
     * fall back to the generic {@see ApiException}.
     *
     * @param array<string, mixed>|null $body
     */
    public static function apiErrorFor(
        int $httpStatus,
        string $message,
        ?array $body = null,
        ?string $requestId = null,
        ?int $retryAfterMs = null,
    ): ApiException {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return new AuthException($message, $httpStatus, $body, $requestId);
        }
        if ($httpStatus === 404) {
            return new NotFoundException($message, $httpStatus, $body, $requestId);
        }
        if ($httpStatus === 429) {
            return new RateLimitException($message, $httpStatus, $body, $requestId, $retryAfterMs);
        }
        if ($httpStatus >= 500) {
            return new ServerException($message, $httpStatus, $body, $requestId);
        }
        if ($httpStatus >= 400) {
            return new ValidationException($message, $httpStatus, $body, $requestId);
        }

        return new ApiException($message, $httpStatus, $body, $requestId);
    }

    /**
     * Auto-discover a PSR-18 HTTP client. Prefers Guzzle (the suggested default),
     * configured with the given per-request timeout (in ms).
     *
     * @throws \RuntimeException when no supported client is installed
     */
    public static function discoverHttpClient(int $timeoutMs = 30_000): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            $seconds = $timeoutMs / 1000;

            return new \GuzzleHttp\Client([
                'timeout' => $seconds,
                'connect_timeout' => $seconds,
                'http_errors' => false,
            ]);
        }

        throw new \RuntimeException(
            'No PSR-18 HTTP client found. Install guzzlehttp/guzzle, '
            . 'or pass your own ClientInterface to the SDK constructor.',
        );
    }

    /**
     * Auto-discover a PSR-17 request factory (Guzzle's, then Nyholm's).
     *
     * @throws \RuntimeException when none is installed
     */
    public static function discoverRequestFactory(): RequestFactoryInterface
    {
        if (class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            return new \GuzzleHttp\Psr7\HttpFactory();
        }
        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            return new \Nyholm\Psr7\Factory\Psr17Factory();
        }

        throw new \RuntimeException(
            'No PSR-17 request factory found. Install guzzlehttp/guzzle or nyholm/psr7, '
            . 'or pass your own RequestFactoryInterface to the SDK constructor.',
        );
    }

    /**
     * Auto-discover a PSR-17 stream factory (Guzzle's, then Nyholm's).
     *
     * @throws \RuntimeException when none is installed
     */
    public static function discoverStreamFactory(): StreamFactoryInterface
    {
        if (class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            return new \GuzzleHttp\Psr7\HttpFactory();
        }
        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            return new \Nyholm\Psr7\Factory\Psr17Factory();
        }

        throw new \RuntimeException(
            'No PSR-17 stream factory found. Install guzzlehttp/guzzle or nyholm/psr7, '
            . 'or pass your own StreamFactoryInterface to the SDK constructor.',
        );
    }

    private static function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * Delay before the next attempt: exponential with full jitter. The jitter
     * matters — without it every client that saw the same outage retries in
     * lockstep and reproduces the spike that caused it.
     */
    private static function backoffMs(int $attempt): int
    {
        $ceiling = min(self::BASE_BACKOFF_MS * (2 ** $attempt), self::MAX_BACKOFF_MS);

        return random_int(0, (int) $ceiling);
    }

    /** `Retry-After` is either seconds or an HTTP date. Returns ms, or null. */
    private static function parseRetryAfter(?string $header): ?int
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (is_numeric($header)) {
            return (int) max(0, (float) $header * 1000);
        }
        $date = strtotime($header);
        if ($date === false) {
            return null;
        }

        return (int) max(0, ($date * 1000) - (microtime(true) * 1000));
    }

    private static function looksLikeTimeout(string $message): bool
    {
        return stripos($message, 'timed out') !== false
            || stripos($message, 'timeout') !== false;
    }

    private static function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function emit(array $event): void
    {
        if ($this->onEvent === null) {
            return;
        }
        try {
            ($this->onEvent)($event);
        } catch (\Throwable) {
            // A broken logger must never break a payment.
        }
    }
}
