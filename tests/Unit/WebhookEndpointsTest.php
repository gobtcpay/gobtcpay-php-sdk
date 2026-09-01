<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Unit;

use GoBTCPay\PosApiSdk\Dto\WebhookEndpoint;
use GoBTCPay\PosApiSdk\Dto\WebhookEvent;
use GoBTCPay\PosApiSdk\Dto\WebhookScope;
use GoBTCPay\PosApiSdk\Exception\AuthException;
use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;
use GoBTCPay\PosApiSdk\Exception\NotFoundException;
use GoBTCPay\PosApiSdk\GoBTCPayServer;
use GoBTCPay\PosApiSdk\Tests\Support\FakeHttpClient;
use GoBTCPay\PosApiSdk\Tests\Support\Responses;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/** `listWebhooks()` and `testWebhook()` — endpoint management from the shop. */
final class WebhookEndpointsTest extends TestCase
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

    public function testListWebhooksParsesEndpointsAndHitsTheRightPath(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success([
            'items' => [Responses::webhookEndpoint()],
            'totalCount' => 1,
        ]));

        $page = $this->client($http)->listWebhooks();

        self::assertSame(1, $page['totalCount']);
        self::assertCount(1, $page['items']);

        $endpoint = $page['items'][0];
        self::assertInstanceOf(WebhookEndpoint::class, $endpoint);
        self::assertSame('wh_1', $endpoint->id);
        self::assertSame('https://shop.example.com/?wc-api=gobtcpay', $endpoint->url);
        self::assertSame('WooCommerce', $endpoint->label);
        self::assertTrue($endpoint->isActive());
        self::assertTrue($endpoint->subscribesTo(WebhookEvent::TYPE_PAYMENT_STATUS_UPDATED));
        self::assertTrue($endpoint->scope->isMerchantWide());

        $request = $http->lastRequest();
        self::assertNotNull($request);
        self::assertStringEndsWith('/merchant/webhook/list', (string) $request->getUri());
        self::assertSame('Bearer sk_live_secret', $request->getHeaderLine('Authorization'));
    }

    public function testListWebhooksSendsPaginationAndStatusFilter(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['items' => [], 'totalCount' => 0]));

        $this->client($http)->listWebhooks(status: WebhookEndpoint::STATUS_ACTIVE, limit: 10, skip: 20);

        $body = $http->lastRequestJson();
        self::assertSame(10, $body['pagination']['limit']);
        self::assertSame(20, $body['pagination']['skip']);
        self::assertSame('active', $body['filters']['status']);
    }

    /**
     * A merchant that has registered nothing is not an error, and the caller
     * must be able to tell that apart from a refusal.
     */
    public function testEmptyListIsASuccessfulAnswer(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['items' => [], 'totalCount' => 0]));

        $page = $this->client($http)->listWebhooks();

        self::assertSame([], $page['items']);
        self::assertSame(0, $page['totalCount']);
    }

    /**
     * Webhook management is merchant-level, so a key that cannot do it is
     * refused with 403 — and the SDK must hand the caller the platform's own
     * message, because the status code alone does not say which of several
     * causes applies.
     */
    public function testListWebhooksSurfacesARefusalWithThePlatformsOwnMessage(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::failure(
            403,
            message: 'This API key is limited to a single store; managing webhooks requires a merchant-level key',
        ));

        try {
            $this->client($http)->listWebhooks();
            self::fail('expected the 403 to surface');
        } catch (AuthException $e) {
            self::assertSame(403, $e->httpStatus);
            self::assertStringContainsString('limited to a single store', $e->getMessage());
        }
    }

    /**
     * The same 403 covers a revoked key, and nothing in the response
     * distinguishes it from the store-scoped case — which is why the SDK
     * quotes rather than diagnoses.
     */
    public function testARevokedKeyIsRefusedIdenticallyAndCarriesItsOwnMessage(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::failure(403, message: 'API key revoked'));

        try {
            $this->client($http)->listWebhooks();
            self::fail('expected the 403 to surface');
        } catch (AuthException $e) {
            self::assertSame(403, $e->httpStatus);
            self::assertStringContainsString('revoked', $e->getMessage());
        }
    }

    /** Truncation is detectable: the page is smaller than the reported total. */
    public function testATruncatedListReportsTheFullTotalCount(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success([
            'items' => [Responses::webhookEndpoint()],
            'totalCount' => 7,
        ]));

        $page = $this->client($http)->listWebhooks(limit: 1);

        self::assertCount(1, $page['items']);
        self::assertSame(7, $page['totalCount']);
    }

    /** The page size is clamped to the API maximum rather than sent through. */
    public function testListWebhooksClampsThePageSize(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['items' => [], 'totalCount' => 0]));

        $this->client($http)->listWebhooks(limit: 5000);

        self::assertSame(100, $http->lastRequestJson()['pagination']['limit']);
    }

    public function testStoreScopedEndpointCarriesItsStoreId(): void
    {
        $endpoint = WebhookEndpoint::fromArray(Responses::webhookEndpoint(scopeType: 'store'));

        self::assertSame(WebhookScope::TYPE_STORE, $endpoint->scope->type);
        self::assertSame('store_1', $endpoint->scope->storeId);
        self::assertFalse($endpoint->scope->isMerchantWide());
    }

    public function testDisabledEndpointIsNotActive(): void
    {
        $endpoint = WebhookEndpoint::fromArray(Responses::webhookEndpoint(status: 'disabled'));

        self::assertFalse($endpoint->isActive());
    }

    /** An unknown future status is carried through, not rejected. */
    public function testUnknownStatusIsPreserved(): void
    {
        $endpoint = WebhookEndpoint::fromArray(Responses::webhookEndpoint(status: 'paused'));

        self::assertSame('paused', $endpoint->status);
        self::assertFalse($endpoint->isActive());
    }

    public function testTestWebhookPostsTheWebhookId(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['ok' => true]));

        $this->client($http)->testWebhook('wh_1');

        $request = $http->lastRequest();
        self::assertNotNull($request);
        self::assertStringEndsWith('/merchant/webhook/test', (string) $request->getUri());
        self::assertSame('wh_1', $http->lastRequestJson()['webhookId']);
    }

    /**
     * One attempt only: a retry would queue a second delivery, and the call
     * carries no idempotency key.
     */
    public function testTestWebhookIsNotRetried(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::failure(503, message: 'upstream down'));

        try {
            $this->client($http)->testWebhook('wh_1');
            self::fail('expected the 503 to surface');
        } catch (GoBTCPayException) {
            // expected
        }

        self::assertCount(1, $http->requests);
    }

    public function testTestWebhookRejectsAnUnknownEndpoint(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::failure(404, message: 'Webhook not found'));

        $this->expectException(NotFoundException::class);
        $this->client($http)->testWebhook('wh_missing');
    }

    /** `ok` other than true is reported, not swallowed. */
    public function testTestWebhookThrowsWhenTheDeliveryWasNotQueued(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['ok' => false]));

        $this->expectException(GoBTCPayException::class);
        $this->expectExceptionMessage('did not queue');
        $this->client($http)->testWebhook('wh_1');
    }
}
