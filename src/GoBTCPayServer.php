<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk;

use Generator;
use GoBTCPay\PosApiSdk\Dto\Payment;
use GoBTCPay\PosApiSdk\Dto\PaymentListItem;
use GoBTCPay\PosApiSdk\Dto\PaymentStatus;
use GoBTCPay\PosApiSdk\Dto\WebhookEndpoint;
use GoBTCPay\PosApiSdk\Dto\WebhookEndpointWithSecret;
use GoBTCPay\PosApiSdk\Dto\WebhookScope;
use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;
use GoBTCPay\PosApiSdk\Exception\WebhookSearchLimitException;
use InvalidArgumentException;
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
    public const SDK_VERSION = '1.0.1';

    private const DEFAULT_HOST = 'https://api.gobtcpay.com/public/api';
    private const DEFAULT_TIMEOUT_MS = 30_000;
    private const DEFAULT_MAX_RETRIES = 2;
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 100;

    /** Page size of {@see findWebhookByUrl}. */
    private const WEBHOOK_SEARCH_PAGE_SIZE = 100;

    /** Pages {@see findWebhookByUrl} reads before giving up: 20 x 100 = 2000 endpoints. */
    private const WEBHOOK_SEARCH_MAX_PAGES = 20;

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
     * payment reaches one of the statuses it stops at. Call `->poll()` to block
     * until then.
     *
     * @param array<string, mixed> $options See {@see PaymentPoller}. Must include `paymentId`.
     */
    public function watchPayment(array $options): PaymentPoller
    {
        /** @var array{paymentId: string} $options */
        return new PaymentPoller(fn (string $id): Payment => $this->getPayment($id), $options);
    }

    /**
     * Register a webhook endpoint for the merchant.
     *
     * Meant for a shop integration that connects itself: it knows its own
     * handler URL and holds the merchant secret key, and nobody has to open the
     * dashboard. Without `$scope` the endpoint is registered merchant-wide,
     * which is what an onboarding flow wants — it has no store id to hand.
     *
     * **The signing secret is returned here and only here.** Save
     * {@see WebhookEndpointWithSecret::$signingSecret} immediately: a listing or
     * a lookup never contains it, and it cannot be read back later. Rotating the
     * secret is the only way to obtain a new one after creation, and it
     * invalidates the previous one at once.
     *
     * **Not retried.** There is no idempotency key for this call, so a repeat
     * after a timeout or a dropped connection could register a second endpoint.
     * After a {@see \GoBTCPay\PosApiSdk\Exception\NetworkException} the outcome
     * is unknown: look the endpoint up with {@see findWebhookByUrl} before
     * deciding to create again.
     *
     * **Duplicates are not prevented.** The platform neither checks the URL
     * against existing endpoints nor limits how many a merchant may have. "Find
     * by URL, then create" is two requests with nothing atomic between them: two
     * processes connecting the same shop at once will both find nothing and both
     * create, leaving two endpoints with one URL. Preventing that — a lock held
     * for the duration of the connection, say — is the caller's job.
     *
     * **A rejected URL** raises {@see \GoBTCPay\PosApiSdk\Exception\ValidationException}
     * when the platform refuses it (not `https`, a private-network host, …).
     * The machine-readable reason, a `webhook_url_*` code, comes through
     * unchanged in {@see \GoBTCPay\PosApiSdk\Exception\ApiException::type()}.
     *
     * Refused for a store-scoped API key, like every webhook management call —
     * with the same undifferentiated 403 as {@see listWebhooks}. **A refusal does
     * not say why**: the platform answers HTTP 403
     * ({@see \GoBTCPay\PosApiSdk\Exception\AuthException}) with the same
     * `permission_error` for a store-scoped key, a revoked key, an unknown key, a
     * publishable key and an inactive merchant, and there is no machine-readable
     * marker that tells them apart. The messages differ, but the message text is
     * not part of the contract: show it to a person, never branch on it.
     *
     * The key's restriction and the endpoint's `$scope` are two different
     * things. `$scope` only filters which deliveries reach the endpoint and has
     * nothing to do with what the key may do.
     *
     * Deliveries of type `payment.status.updated` are verified on the receiving
     * side with {@see webhooks}.
     *
     * @param string $url Absolute URL the platform will deliver to.
     * @param WebhookScope|null $scope What the endpoint receives deliveries for.
     *                                 Null means the whole merchant.
     * @param list<string>|null $events Event types to subscribe to; null leaves the platform default.
     * @param string|null $label Free-form label shown next to the endpoint; null for none.
     *
     * @throws InvalidArgumentException If `$scope` is a store scope without `storeId`, or a POS scope without `posTerminalId`. Thrown before any request is made.
     * @throws \GoBTCPay\PosApiSdk\Exception\ApiException If the platform rejects the request.
     * @throws GoBTCPayException If the platform answers without an endpoint.
     */
    public function createWebhook(
        string $url,
        ?WebhookScope $scope = null,
        ?array $events = null,
        ?string $label = null,
    ): WebhookEndpointWithSecret {
        $scope ??= new WebhookScope(WebhookScope::TYPE_MERCHANT);

        // Refuse an incomplete scope before any request is made: a store scope
        // without its store, or a POS scope without its terminal, names nothing.
        $scopeBody = ['type' => $scope->type];
        if ($scope->type === WebhookScope::TYPE_STORE) {
            if ($scope->storeId === null) {
                throw new InvalidArgumentException('A webhook scope of type "store" requires a storeId');
            }
            $scopeBody['storeId'] = $scope->storeId;
        } elseif ($scope->type === WebhookScope::TYPE_POS) {
            if ($scope->posTerminalId === null) {
                throw new InvalidArgumentException('A webhook scope of type "pos" requires a posTerminalId');
            }
            $scopeBody['posTerminalId'] = $scope->posTerminalId;
        }

        $optional = array_filter(
            ['events' => $events, 'label' => $label],
            static fn (mixed $v): bool => $v !== null,
        );

        // Not retried: there is no idempotency key for this call, and a repeat
        // after a timeout could register a second endpoint for the same URL.
        $response = $this->transport->post(
            '/merchant/webhook/create',
            ['url' => $url, 'scope' => $scopeBody] + $optional,
            retry: false,
        );

        $webhook = $response['webhook'] ?? null;
        if (!is_array($webhook)) {
            throw new GoBTCPayException('The platform did not return the created webhook endpoint');
        }

        /** @var array<string, mixed> $webhook */
        return WebhookEndpointWithSecret::fromArray($webhook);
    }

    /**
     * List the merchant's configured webhook endpoints.
     *
     * Read-only, and an empty list is a perfectly successful answer: a merchant
     * that has registered nothing yet is not an error. That makes this the
     * cheapest probe of whether an API key can manage webhooks at all —
     * endpoints are merchant-level configuration, so a key restricted to one
     * store is refused.
     *
     * **A refusal does not say why.** The platform answers HTTP 403
     * ({@see \GoBTCPay\PosApiSdk\Exception\AuthException}) with the same
     * `permission_error` for a store-scoped key, a revoked key, an unknown key,
     * a publishable key and an inactive merchant. Report the server's own
     * message; do not infer the cause from the status code.
     *
     * **Returns one page.** Compare `count($result['items'])` against
     * `totalCount` and page with `$skip` before concluding that a particular
     * URL is not registered — on a merchant with more endpoints than `$limit`,
     * a missing entry may simply be on the next page.
     *
     * @param string|null $status Filter by `WebhookEndpoint::STATUS_*`; null for both.
     * @param int $limit Page size, clamped to 1..100.
     *
     * @return array{items: list<WebhookEndpoint>, totalCount: int}
     */
    public function listWebhooks(
        ?string $status = null,
        int $limit = self::DEFAULT_PAGE_SIZE,
        int $skip = 0,
    ): array {
        $filters = array_filter(
            ['status' => $status],
            static fn (mixed $v): bool => $v !== null,
        );

        $response = $this->transport->post(
            '/merchant/webhook/list',
            [
                'pagination' => [
                    'limit' => self::clampPageSize($limit),
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
                $items[] = WebhookEndpoint::fromArray($item);
            }
        }

        return [
            'items' => $items,
            'totalCount' => (int) ($response['totalCount'] ?? 0),
        ];
    }

    /**
     * Find the merchant's webhook endpoint registered on exactly this URL.
     *
     * Pages through {@see listWebhooks} itself, 100 endpoints at a time and at
     * most 20 pages — **2000 endpoints at most**. The limit is finite on purpose
     * so a misbehaving server can never keep the loop running forever. The URL
     * is compared for exact equality, not normalised.
     *
     * There are three outcomes, and they are different answers:
     *
     * - an endpoint — it was found;
     * - `null` — the listing ended (a page came back short) and the URL is not
     *   in it: **no such URL is registered**;
     * - {@see WebhookSearchLimitException} — the limit was reached and the last
     *   page was still full: **the answer is unknown**, because the endpoints
     *   past the limit were never read. Do not treat this as `null` and create:
     *   that is how one URL ends up registered twice.
     *
     * The search stops on the length of a page, not on `totalCount`. The server
     * computes the total in a separate query, and trusting an understated one
     * would end the search early with a false "not found" — the outcome after
     * which a caller creates a second endpoint. The reported total is used only
     * in the exception message.
     *
     * **"Find, then create" is not atomic.** It is two requests, and two
     * processes connecting the same shop at once will both find nothing and
     * both call {@see createWebhook}, leaving two endpoints with one URL. The
     * caller is responsible for idempotency — for example a lock held while a
     * shop is being connected.
     *
     * Merchant-level, so refused for a store-scoped API key with the same
     * undifferentiated 403 as {@see listWebhooks}: the refusal does not say why
     * (store-scoped, revoked, unknown or publishable key, inactive merchant),
     * and there is no machine-readable marker that tells these apart. Show the
     * message, never branch on it.
     *
     * @throws WebhookSearchLimitException If 20 pages were read, the last one still full, and the URL did not turn up.
     * @throws \GoBTCPay\PosApiSdk\Exception\ApiException If a listing request fails.
     */
    public function findWebhookByUrl(string $url): ?WebhookEndpoint
    {
        $scanned = 0;
        $totalCount = 0;

        for ($page = 0; $page < self::WEBHOOK_SEARCH_MAX_PAGES; $page++) {
            $result = $this->listWebhooks(
                null,
                self::WEBHOOK_SEARCH_PAGE_SIZE,
                $page * self::WEBHOOK_SEARCH_PAGE_SIZE,
            );

            foreach ($result['items'] as $item) {
                if (hash_equals($url, $item->url)) {
                    return $item;
                }
            }

            $scanned += count($result['items']);
            $totalCount = $result['totalCount'];

            // A short page, empty included, means the listing ended. The
            // server's totalCount is deliberately not consulted: see the docblock.
            if (count($result['items']) < self::WEBHOOK_SEARCH_PAGE_SIZE) {
                return null;
            }
        }

        throw new WebhookSearchLimitException(self::WEBHOOK_SEARCH_MAX_PAGES, $scanned, $totalCount);
    }

    /**
     * Ask the platform to send a test delivery to one of your endpoints.
     *
     * **Returning normally means the delivery was queued, not that it arrived.**
     * Deliveries are dispatched by a scheduled job, so the test lands at your
     * endpoint some time after this call returns, and it can still fail there —
     * an unreachable URL, a TLS error, a non-2xx response. Whether the webhook
     * actually works is answered at the receiving end, by observing the
     * delivery; it is not answered by this method.
     *
     * That is also why nothing is returned. A boolean here would read as "the
     * webhook works", which is the one thing this call cannot tell you.
     *
     * Refused for a store-scoped API key, like every webhook management call —
     * with the same undifferentiated 403 as {@see listWebhooks}.
     *
     * @throws GoBTCPayException If the platform declines to queue the delivery.
     */
    public function testWebhook(string $webhookId): void
    {
        // Not retried: a repeat would queue a second delivery, and there is no
        // idempotency key for this call. A timeout leaves the outcome unknown,
        // which is the honest answer — the receiving end settles it.
        $response = $this->transport->post(
            '/merchant/webhook/test',
            ['webhookId' => $webhookId],
            retry: false,
        );

        if (($response['ok'] ?? false) !== true) {
            throw new GoBTCPayException('The platform did not queue the test delivery');
        }
    }

    /** Create a handler that verifies and dispatches webhook deliveries. */
    public function webhooks(
        string $signingSecret,
        int $toleranceSeconds = 300,
        int $dedupeCacheSize = 1000,
    ): WebhookHandler {
        return new WebhookHandler($signingSecret, $toleranceSeconds, $dedupeCacheSize);
    }

    /** Clamp a requested page size to the range the platform accepts: 1..{@see MAX_PAGE_SIZE}. */
    private static function clampPageSize(int $limit): int
    {
        return max(1, min($limit, self::MAX_PAGE_SIZE));
    }
}
