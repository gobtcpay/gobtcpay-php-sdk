<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Integration;

use GoBTCPay\PosApiSdk\Dto\Payment;
use GoBTCPay\PosApiSdk\Dto\PaymentStatus;
use GoBTCPay\PosApiSdk\GoBTCPay;
use GoBTCPay\PosApiSdk\GoBTCPayServer;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests against a live POS API on the TEST contour.
 *
 * Opt-in and self-skipping: they only run when the relevant credentials are
 * present in the environment, so `composer test` and CI stay green without
 * secrets. Never point these at production keys.
 *
 * @group integration
 */
final class PaymentsTest extends TestCase
{
    private static function env(string $key): ?string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? null : $value;
    }

    public function testPosCreateAndGetPayment(): void
    {
        $apiKey = self::env('POS_API_KEY');
        $terminalId = self::env('POS_TERMINAL_ID');
        if ($apiKey === null || $terminalId === null) {
            self::markTestSkipped('Set POS_API_KEY and POS_TERMINAL_ID to run the POS integration test.');
        }

        $btcPay = new GoBTCPay(
            apiKey: $apiKey,
            posTerminalId: $terminalId,
            baseUrl: self::env('POS_API_BASE_URL') ?? 'https://pay.dev.gobtcpay.com/public/api/v1.1',
        );

        $payment = $btcPay->createPayment(amount: 1, currency: 'USD', description: 'SDK integration test');

        self::assertNotSame('', $payment->paymentId);
        self::assertNotSame('', $payment->qrString);
        self::assertInstanceOf(PaymentStatus::class, $payment->status);

        $fetched = $btcPay->getPayment($payment->paymentId);
        self::assertSame($payment->paymentId, $fetched->paymentId);
    }

    public function testServerCreateAndCancelPayment(): void
    {
        $secretKey = self::env('GOBTCPAY_SECRET_KEY');
        if ($secretKey === null || !str_starts_with($secretKey, 'sk_live_')) {
            self::markTestSkipped('Set GOBTCPAY_SECRET_KEY (sk_live_…) to run the server integration test.');
        }

        $gobtcpay = new GoBTCPayServer(
            apiKey: $secretKey,
            baseUrl: self::env('POS_API_BASE_URL') !== null
                ? str_replace('/v1.1', '/v1.2', (string) self::env('POS_API_BASE_URL'))
                : 'https://pay.dev.gobtcpay.com/public/api/v1.2',
        );

        $payment = $gobtcpay->createPayment(
            amount: 1,
            currency: 'USD',
            externalId: 'sdk-int-' . bin2hex(random_bytes(4)),
        );

        self::assertInstanceOf(Payment::class, $payment);
        self::assertNotSame('', $payment->paymentId);

        // Newly created payments are unpaid, so cancel should succeed.
        $canceled = $gobtcpay->cancelPayment($payment->paymentId);
        self::assertSame($payment->paymentId, $canceled->paymentId);
    }
}
