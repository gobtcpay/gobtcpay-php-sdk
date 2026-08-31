<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk;

use Generator;
use GoBTCPay\PosApiSdk\Dto\Payment;
use GoBTCPay\PosApiSdk\Dto\PaymentListItem;
use GoBTCPay\PosApiSdk\Dto\PaymentStatus;
use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Server-side client for shops: create a payment for an order, track it, and
 * verify webhooks. Authenticates with the merchant's secret API key (`sk_live_…`).
 *
 * ```php
 * $gobtcpay = new GoBTCPayServer(apiKey: getenv('GOBTCPAY_SECRET_KEY'));
 * $payment = $gobtcpay->createPayment(amount: 49.99, currency: 'USD', externalId: "order-{$order->id}");
 * header('Location: ' . $payment->checkoutUrl);
 * ```
 */
final class GoBTCPayServer
{
    /** API version this client targets. Pinned; a breaking bump ships as a major. */
    public const SERVER_API_VERSION = 'v1.2';

    /** Bumped by the release pipeline; sent as the User-Agent for support triage. */
    public const SDK_VERSION = '1.0.0';

    private const DEFAULT_HOST = 'https://api.gobtcpay.com/public/api';
    private const DEFAULT_TIMEOUT_MS = 30_000;
    private const DEFAULT_MAX_RETRIES = 2;
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 100;

    private const SECRET_KEY_PREFIX = 'sk_live_';
    private const PUBLISHABLE_KEY_PREFIX = 'pk_live_';

    private readonly Transport $transport;

    /**
     * @param string $apiKey Merchant secret API key (`sk_live_…`). Server-side only.
     * @param string|null $baseUrl API base URL. Defaults to production.
     * @param int $timeoutMs Per-request timeout in milliseconds.
     * @param int $maxRetries Retries after the first attempt for network failures, 429 and 5xx.
     * @param (callable(array<string, mixed>): void)|null $onEvent Called once per request attempt.
     *
     * PSR components are optional: when omitted, Guzzle is auto-discovered.
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?callable $onEvent = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if ($apiKey === '') {
            throw new GoBTCPayException('`apiKey` is required');
        }
        if (str_starts_with($apiKey, self::PUBLISHABLE_KEY_PREFIX)) {
            throw new GoBTCPayException(
                'A publishable key (pk_live_…) cannot be used here — pass the secret key (sk_live_…)',
            );
        }
        if (!str_starts_with($apiKey, self::SECRET_KEY_PREFIX)) {
            throw new GoBTCPayException('`apiKey` must be a secret key (sk_live_…)');
        }

        $this->transport = new Transport(
            httpClient: $httpClient ?? Transport::discoverHttpClient($timeoutMs),
            requestFactory: $requestFactory ?? Transport::discoverRequestFactory(),
            streamFactory: $streamFactory ?? Transport::discoverStreamFactory(),
            authStrategy: Transport::AUTH_BEARER,
            apiKey: $apiKey,
            baseUrl: $baseUrl ?? self::DEFAULT_HOST . '/' . self::SERVER_API_VERSION,
            timeoutMs: $timeoutMs,
            maxRetries: $maxRetries,
            userAgent: 'gobtcpay-server-sdk-php/' . self::SDK_VERSION . ' php/' . PHP_VERSION,
            onEvent: $onEvent,
        );
    }

    /**
     * Create a payment for an order.
     *
     * Pass `externalId` — with it the call is idempotent, so a retry after a
     * timeout returns the payment already created for that order rather than a
     * second one. Without it a retry is not safe, and the SDK will not retry.
     */
    public function createPayment(
        float $amount,
        string $currency,
        ?string $externalId = null,
        ?string $description = null,
        ?int $ttlSeconds = null,
    ): Payment {
        $params = array_filter(
            [
                'amount' => $amount,
                'currency' => $currency,
                'externalId' => $externalId,
                'description' => $description,
                'ttlSeconds' => $ttlSeconds,
            ],
            static fn (mixed $v): bool => $v !== null,
        );

        return Payment::fromArray(
            $this->transport->post('/merchant/payment/create', $params, retry: $externalId !== null),
        );
    }

    /** Fetch the current state of a payment. */
    public function getPayment(string $paymentId): Payment
    {
        return Payment::fromArray(
            $this->transport->post('/merchant/payment/get', ['paymentId' => $paymentId], retry: true),
        );
    }

    /**
     * Cancel an unpaid payment. Fails once funds are in flight (`detected`) or
     * confirmed (`paid`). Cancelling twice is a no-op and succeeds.
     */
    public function cancelPayment(string $paymentId): Payment
    {
        return Payment::fromArray(
            $this->transport->post('/merchant/payment/cancel', ['paymentId' => $paymentId], retry: true),
        );
    }

    /**
     * Fetch one page of the merchant's payments, newest first.
     *
     * @param list<PaymentStatus>|null $status
     * @param array{start: int, end: int}|null $dateRange Inclusive range over creation time, unix seconds.
     *
     * @return array{items: list<PaymentListItem>, totalCount: int}
     */
    public function listPaymentsPage(
        ?array $status = null,
        ?string $externalId = null,
        ?array $dateRange = null,
        int $limit = self::DEFAULT_PAGE_SIZE,
        int $skip = 0,
    ): array {
        $filters = array_filter(
            [
                'status' => $status === null ? null : array_map(static fn (PaymentStatus $s): string => $s->value, $status),
                'externalId' => $externalId,
                'dateRange' => $dateRange,
            ],
            static fn (mixed $v): bool => $v !== null,
        );

        $response = $this->transport->post(
            '/merchant/payment/list',
            [
                'pagination' => [
                    'limit' => min($limit, self::MAX_PAGE_SIZE),
                    'skip' => $skip,
                ],
                'filters' => (object) $filters,
            ],
            retry: true,
        );

        $items = [];
        foreach ((array) ($response['items'] ?? []) as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = PaymentListItem::fromArray($item);
            }
        }

        return [
            'items' => $items,
            'totalCount' => (int) ($response['totalCount'] ?? 0),
        ];
    }

    /**
     * Iterate the merchant's payments, newest first, fetching pages as needed.
     *
     * ```php
     * foreach ($gobtcpay->listPayments(status: [PaymentStatus::Paid]) as $p) {
     *     $this->reconcile($p);
     * }
     * ```
     *
     * @param list<PaymentStatus>|null $status
     * @param array{start: int, end: int}|null $dateRange
     *
     * @return Generator<int, PaymentListItem>
     */
    public function listPayments(
        ?array $status = null,
        ?string $externalId = null,
        ?array $dateRange = null,
        int $limit = self::DEFAULT_PAGE_SIZE,
    ): Generator {
        $limit = min($limit, self::MAX_PAGE_SIZE);
        $skip = 0;

        for (;;) {
            $page = $this->listPaymentsPage(
                status: $status,
                externalId: $externalId,
                dateRange: $dateRange,
                limit: $limit,
                skip: $skip,
            );
            foreach ($page['items'] as $item) {
                yield $item;
            }

            $skip += count($page['items']);
            // Stop on a short page as well as on the count: the total can shift
            // while paging, and a short page already means there is nothing after it.
            if (count($page['items']) < $limit || $skip >= $page['totalCount']) {
                return;
            }
        }
    }

    /**
     * Build a poller that calls {@see getPayment} on an interval until the
     * payment reaches a final status. Call `->poll()` to block until it settles.
     *
     * @param array<string, mixed> $options See {@see PaymentPoller}. Must include `paymentId`.
     */
    public function watchPayment(array $options): PaymentPoller
    {
        /** @var array{paymentId: string} $options */
        return new PaymentPoller(fn (string $id): Payment => $this->getPayment($id), $options);
    }

    /** Create a handler that verifies and dispatches webhook deliveries. */
    public function webhooks(
        string $signingSecret,
        int $toleranceSeconds = 300,
        int $dedupeCacheSize = 1000,
    ): WebhookHandler {
        return new WebhookHandler($signingSecret, $toleranceSeconds, $dedupeCacheSize);
    }
}
