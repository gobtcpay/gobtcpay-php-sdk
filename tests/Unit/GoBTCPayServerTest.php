<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Unit;

use GoBTCPay\PosApiSdk\Dto\PaymentListItem;
use GoBTCPay\PosApiSdk\Dto\PaymentStatus;
use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;
use GoBTCPay\PosApiSdk\GoBTCPayServer;
use GoBTCPay\PosApiSdk\Tests\Support\FakeHttpClient;
use GoBTCPay\PosApiSdk\Tests\Support\Responses;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class GoBTCPayServerTest extends TestCase
{
    private function client(FakeHttpClient $http): GoBTCPayServer
    {
        $factory = new Psr17Factory();

        return new GoBTCPayServer(
            apiKey: 'sk_live_secret',
            baseUrl: 'https://api.example.com/public/api/v1.2',
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    public function testConstructorRejectsPublishableKey(): void
    {
        $this->expectException(GoBTCPayException::class);
        $this->expectExceptionMessage('publishable key');
        new GoBTCPayServer(apiKey: 'pk_live_abc');
    }

    public function testConstructorRequiresSecretKeyPrefix(): void
    {
        $this->expectException(GoBTCPayException::class);
        $this->expectExceptionMessage('must be a secret key');
        new GoBTCPayServer(apiKey: 'random-key');
    }

    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(GoBTCPayException::class);
        $this->expectExceptionMessage('`apiKey` is required');
        new GoBTCPayServer(apiKey: '');
    }

    public function testCreatePaymentSendsCorrectPayloadAndUsesBearerAuth(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(Responses::samplePayment()));

        $payment = $this->client($http)->createPayment(
            amount: 49.99,
            currency: 'USD',
            externalId: 'order-99',
            description: 'Subscription',
        );

        self::assertSame('pay_1', $payment->paymentId);

        $request = $http->lastRequest();
        self::assertNotNull($request);
        self::assertStringEndsWith('/merchant/payment/create', (string) $request->getUri());
        self::assertSame('Bearer sk_live_secret', $request->getHeaderLine('Authorization'));

        $body = $http->lastRequestJson();
        self::assertSame(49.99, $body['amount']);
        self::assertSame('USD', $body['currency']);
        self::assertSame('order-99', $body['externalId']);
        self::assertSame('Subscription', $body['description']);
        // Bearer auth: body is NOT signed.
        self::assertArrayNotHasKey('signature', $body);
        self::assertArrayNotHasKey('ts', $body);
    }

    public function testCancelPaymentSendsPaymentId(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(Responses::samplePayment(status: 'canceled')));

        $payment = $this->client($http)->cancelPayment('pay_1');

        self::assertSame(PaymentStatus::Canceled, $payment->status);
        self::assertStringEndsWith('/merchant/payment/cancel', (string) $http->lastRequest()?->getUri());
        self::assertSame('pay_1', $http->lastRequestJson()['paymentId']);
    }

    public function testListPaymentsPaginatesAcrossPages(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success([
            'items' => [
                Responses::listItem('pay_1'),
                Responses::listItem('pay_2'),
            ],
            'totalCount' => 3,
        ]));
        $http->queueResponse(Responses::success([
            'items' => [Responses::listItem('pay_3')],
            'totalCount' => 3,
        ]));

        $ids = [];
        foreach ($this->client($http)->listPayments(status: [PaymentStatus::Paid], limit: 2) as $item) {
            self::assertInstanceOf(PaymentListItem::class, $item);
            $ids[] = $item->paymentId;
        }

        self::assertSame(['pay_1', 'pay_2', 'pay_3'], $ids);
        self::assertCount(2, $http->requests);

        // Filters + pagination are sent in the expected shape.
        $body = $http->requests[0];
        $decoded = json_decode((string) $body->getBody(), true);
        self::assertSame(2, $decoded['pagination']['limit']);
        self::assertSame(0, $decoded['pagination']['skip']);
        self::assertSame(['paid'], $decoded['filters']['status']);
    }

    public function testListPaymentsStopsOnShortPage(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success([
            'items' => [Responses::listItem('pay_1')],
            'totalCount' => 999,
        ]));

        $ids = [];
        foreach ($this->client($http)->listPayments(limit: 50) as $item) {
            $ids[] = $item->paymentId;
        }

        self::assertSame(['pay_1'], $ids);
        self::assertCount(1, $http->requests);
    }
}
