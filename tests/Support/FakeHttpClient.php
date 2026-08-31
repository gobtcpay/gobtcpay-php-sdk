<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Minimal in-memory PSR-18 client for tests: queue responses (or exceptions)
 * to be returned in FIFO order, and inspect the requests that were sent.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|Throwable> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function queueResponse(ResponseInterface $response): void
    {
        $this->queue[] = $response;
    }

    public function queueException(ClientExceptionInterface $exception): void
    {
        $this->queue[] = $exception;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException('FakeHttpClient: no queued response for ' . $request->getUri());
        }

        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    /** @return array<string, mixed> */
    public function lastRequestJson(): array
    {
        $request = $this->lastRequest();
        if ($request === null) {
            return [];
        }
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
