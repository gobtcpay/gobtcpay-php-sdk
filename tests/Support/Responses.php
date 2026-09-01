<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Small helpers to build PSR-7 responses and a sample payment payload for the
 * transport/client tests.
 */
final class Responses
{
    private static ?Psr17Factory $factory = null;

    private static function factory(): Psr17Factory
    {
        return self::$factory ??= new Psr17Factory();
    }

    /**
     * Build a JSON response.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): ResponseInterface
    {
        $response = self::factory()
            ->createResponse($status)
            ->withHeader('content-type', 'application/json')
            ->withBody(self::factory()->createStream(json_encode($body, JSON_THROW_ON_ERROR)));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * A `success` envelope wrapping the given payload.
     *
     * @param array<string, mixed> $success
     * @param array<string, string> $headers
     */
    public static function success(array $success, int $status = 200, array $headers = []): ResponseInterface
    {
        return self::json(
            ['id' => 'req_1', 'result' => ['$case' => 'success', 'success' => $success]],
            $status,
            $headers,
        );
    }

    /**
     * A `failure` envelope with the given HTTP status.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function failure(
        int $status,
        int $code = 0,
        string $message = 'boom',
        array $data = [],
        array $headers = [],
    ): ResponseInterface {
        $failure = ['code' => $code, 'message' => $message];
        if ($data !== []) {
            $failure['data'] = $data;
        }

        return self::json(
            ['id' => 'req_1', 'result' => ['$case' => 'failure', 'failure' => $failure]],
            $status,
            $headers,
        );
    }

    /** A network-layer exception implementing PSR-18's ClientExceptionInterface. */
    public static function networkException(string $message): ClientExceptionInterface
    {
        return new class ($message) extends RuntimeException implements ClientExceptionInterface {
        };
    }

    /**
     * A representative payment payload as returned by the API.
     *
     * @return array<string, mixed>
     */
    public static function samplePayment(string $status = 'initiated', string $paymentId = 'pay_1'): array
    {
        return [
            'paymentId' => $paymentId,
            'status' => $status,
            'merchantId' => 'm_1',
            'merchantName' => 'Acme',
            'storeId' => 'store_1',
            'posTerminalId' => 'term_1',
            'posTerminalLabel' => 'Front desk',
            'posTerminalLocation' => null,
            'merchantDisplayName' => 'Acme Inc',
            'externalId' => 'order-42',
            'description' => 'Order #42',
            'amount' => 10.0,
            'amountSats' => 15000,
            'currency' => 'USD',
            'currencySymbol' => '$',
            'btcPriceInUsd' => 65000.0,
            'btcPriceInCurrency' => 65000.0,
            'btcAddress' => 'bc1qexample',
            'qrString' => 'bitcoin:bc1qexample?amount=0.00015',
            'expiresAt' => 1_700_000_900,
            'checkoutUrl' => 'https://pay.gobtcpay.com/c/pay_1',
            'version' => 1,
        ];
    }

    /**
     * A representative `payments.list` row.
     *
     * @return array<string, mixed>
     */
    public static function listItem(string $paymentId, string $status = 'paid'): array
    {
        return [
            'paymentId' => $paymentId,
            'status' => $status,
            'amount' => 10.0,
            'amountSats' => 15000,
            'currency' => 'USD',
            'currencySymbol' => '$',
            'description' => 'Order',
            'externalId' => 'order-1',
            'btcAddress' => 'bc1qexample',
            'btcPriceInUsd' => 65000.0,
            'btcPriceInCurrency' => 65000.0,
            'network' => 'bitcoin',
            'createdAt' => 1_700_000_000,
            'expiresAt' => 1_700_000_900,
            'storeId' => 'store_1',
            'posTerminalId' => 'term_1',
            'posTerminalLabel' => null,
            'posTerminalLocation' => null,
        ];
    }

    /**
     * A representative on-chain receipt as returned inside `transactions[]`.
     *
     * Satoshi amounts are canonical decimal strings on the wire, exactly as the
     * API sends them — that is the shape the DTO has to survive.
     *
     * @return array<string, mixed>
     */
    public static function onchainTransaction(bool $confirmed = true): array
    {
        return [
            'txid' => 'a1b2c3',
            'amountSats' => '15000',
            'blockHeight' => $confirmed ? 870_000 : null,
            'blockTime' => $confirmed ? 1_700_000_500 : null,
            // `0`, not null, while it is in the mempool: null is reserved for
            // "the chain tip is unknown", which only happens once it IS in a
            // block. See `computeConfirmations` on the backend.
            'confirmations' => $confirmed ? 3 : 0,
            'feeSats' => $confirmed ? '250' : null,
        ];
    }

    /**
     * A representative webhook endpoint row as returned by `webhook.list`.
     *
     * @return array<string, mixed>
     */
    public static function webhookEndpoint(
        string $id = 'wh_1',
        string $status = 'active',
        string $scopeType = 'merchant',
    ): array {
        $scope = match ($scopeType) {
            'store' => ['type' => 'store', 'storeId' => 'store_1'],
            'pos' => ['type' => 'pos', 'posTerminalId' => 'term_1'],
            default => ['type' => 'merchant'],
        };

        return [
            'id' => $id,
            'merchantId' => 'm_1',
            'url' => 'https://shop.example.com/?wc-api=gobtcpay',
            'scope' => $scope,
            'events' => ['payment.status.updated'],
            'status' => $status,
            'label' => 'WooCommerce',
            'createdAt' => 1_700_000_000,
            'updatedAt' => 1_700_000_100,
        ];
    }
}
