<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Unit;

use GoBTCPay\PosApiSdk\Dto\PaymentStatus;
use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;
use GoBTCPay\PosApiSdk\GoBTCPay;
use GoBTCPay\PosApiSdk\Tests\Support\FakeHttpClient;
use GoBTCPay\PosApiSdk\Tests\Support\Responses;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class GoBTCPayTest extends TestCase
{
    private function client(FakeHttpClient $http, ?string $posTerminalId = 'term_default'): GoBTCPay
    {
        $factory = new Psr17Factory();

        return new GoBTCPay(
            apiKey: 'terminal-secret',
            posTerminalId: $posTerminalId,
            baseUrl: 'https://api.example.com/public/api/v1.1',
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(GoBTCPayException::class);
        $this->expectExceptionMessage('`apiKey` is required');
        new GoBTCPay(apiKey: '');
    }

    public function testCreatePaymentRequiresPosTerminalId(): void
    {
        $http = new FakeHttpClient();
        $client = $this->client($http, posTerminalId: null);

        $this->expectException(GoBTCPayException::class);
        $this->expectExceptionMessage('`posTerminalId` is required');
        $client->createPayment(amount: 10, currency: 'USD');
    }

    public function testCreatePaymentSendsCorrectPayload(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(Responses::samplePayment()));

        $payment = $this->client($http)->createPayment(
            amount: 10,
            currency: 'USD',
            description: 'Order #42',
            externalId: 'order-42',
        );

        self::assertSame('pay_1', $payment->paymentId);
        self::assertSame(PaymentStatus::Initiated, $payment->status);

        $request = $http->lastRequest();
        self::assertNotNull($request);
        self::assertStringEndsWith('/pos/transaction/create-payment', (string) $request->getUri());

        $body = $http->lastRequestJson();
        self::assertSame('term_default', $body['posTerminalId']);
        self::assertSame(10, $body['amount']);
        self::assertSame('USD', $body['currency']);
        self::assertSame('Order #42', $body['description']);
        self::assertSame('order-42', $body['externalId']);
        // HMAC auth: request is signed.
        self::assertArrayHasKey('ts', $body);
        self::assertArrayHasKey('signature', $body);
    }

    public function testCreatePaymentPerCallTerminalOverridesDefault(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(Responses::samplePayment()));

        $this->client($http)->createPayment(amount: 5, currency: 'EUR', posTerminalId: 'term_override');

        self::assertSame('term_override', $http->lastRequestJson()['posTerminalId']);
    }

    public function testGetPaymentSendsPaymentId(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(Responses::samplePayment(status: 'paid')));

        $payment = $this->client($http)->getPayment('pay_1');

        self::assertSame(PaymentStatus::Paid, $payment->status);
        self::assertStringEndsWith('/pos/transaction/get-payment', (string) $http->lastRequest()?->getUri());
        self::assertSame('pay_1', $http->lastRequestJson()['paymentId']);
    }
}
