<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk;

use GoBTCPay\PosApiSdk\Dto\Payment;
use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * GoBTC Pay POS API client.
 *
 * Initialize once with your terminal `apiKey`, then call the payment methods.
 * Authentication is handled internally: every request is signed with a fresh
 * HMAC-SHA256 signature and timestamp at the moment it is sent — there is no
 * long-lived token to manage or refresh.
 *
 * ```php
 * $btcPay = new GoBTCPay(apiKey: $apiKey, posTerminalId: $terminalId);
 * $payment = $btcPay->createPayment(amount: 10, currency: 'USD');
 * echo $payment->qrString;
 * ```
 */
final class GoBTCPay
{
    /** API version this SDK targets. Pinned; a breaking bump ships as a new major. */
    public const API_VERSION = 'v1.1';

    /** Bumped by the release pipeline; sent as the User-Agent for support triage. */
    public const SDK_VERSION = '1.0.0';

    private const DEFAULT_HOST = 'https://api.gobtcpay.com/public/api';
    private const DEFAULT_TIMEOUT_MS = 30_000;

    private readonly Transport $transport;
    private readonly ?string $defaultPosTerminalId;

    /**
     * @param string $apiKey Per-terminal secret used to sign every request (HMAC key).
     * @param string|null $posTerminalId Default terminal for `createPayment`.
     * @param string|null $baseUrl API base URL. Defaults to production.
     * @param int $timeoutMs Per-request timeout in milliseconds.
     *
     * PSR components are optional: when omitted, Guzzle is auto-discovered.
     */
    public function __construct(
        string $apiKey,
        ?string $posTerminalId = null,
        ?string $baseUrl = null,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if ($apiKey === '') {
            throw new GoBTCPayException('`apiKey` is required');
        }

        $this->defaultPosTerminalId = $posTerminalId;
        $this->transport = new Transport(
            httpClient: $httpClient ?? Transport::discoverHttpClient($timeoutMs),
            requestFactory: $requestFactory ?? Transport::discoverRequestFactory(),
            streamFactory: $streamFactory ?? Transport::discoverStreamFactory(),
            authStrategy: Transport::AUTH_HMAC,
            apiKey: $apiKey,
            baseUrl: $baseUrl ?? self::DEFAULT_HOST . '/' . self::API_VERSION,
            timeoutMs: $timeoutMs,
            // The POS client does not retry: a terminal has a human in front of
            // it who sees the failure and taps again.
            maxRetries: 0,
            userAgent: 'gobtcpay-pos-api-sdk-php/' . self::SDK_VERSION,
        );
    }

    /**
     * Create a payment and get back the QR string to present to the payer.
     *
     * @param string|null $posTerminalId Falls back to the constructor default.
     */
    public function createPayment(
        float $amount,
        string $currency,
        ?string $posTerminalId = null,
        ?string $description = null,
        ?int $ttlSeconds = null,
        ?string $externalId = null,
    ): Payment {
        $posTerminalId ??= $this->defaultPosTerminalId;
        if ($posTerminalId === null || $posTerminalId === '') {
            throw new GoBTCPayException(
                '`posTerminalId` is required (pass it here or in the constructor)',
            );
        }

        $params = array_filter(
            [
                'posTerminalId' => $posTerminalId,
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'ttlSeconds' => $ttlSeconds,
                'externalId' => $externalId,
            ],
            static fn (mixed $v): bool => $v !== null,
        );

        return Payment::fromArray(
            $this->transport->post('/pos/transaction/create-payment', $params),
        );
    }

    /** Fetch the current state of a payment by id. */
    public function getPayment(string $paymentId): Payment
    {
        return Payment::fromArray(
            $this->transport->post('/pos/transaction/get-payment', ['paymentId' => $paymentId]),
        );
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
     * Create a webhook handler for verifying and dispatching
     * `payment.status.updated` deliveries.
     */
    public function webhooks(
        string $signingSecret,
        int $toleranceSeconds = 300,
        int $dedupeCacheSize = 1000,
    ): WebhookHandler {
        return new WebhookHandler($signingSecret, $toleranceSeconds, $dedupeCacheSize);
    }
}
