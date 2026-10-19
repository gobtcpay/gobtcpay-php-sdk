<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Unit;

use GoBTCPay\PosApiSdk\Dto\WebhookEndpoint;
use GoBTCPay\PosApiSdk\Dto\WebhookEndpointWithSecret;
use GoBTCPay\PosApiSdk\Dto\WebhookEvent;
use GoBTCPay\PosApiSdk\Dto\WebhookScope;
use GoBTCPay\PosApiSdk\Exception\ApiException;
use GoBTCPay\PosApiSdk\Exception\AuthException;
use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;
use GoBTCPay\PosApiSdk\Exception\NetworkException;
use GoBTCPay\PosApiSdk\Exception\NotFoundException;
use GoBTCPay\PosApiSdk\Exception\ValidationException;
use GoBTCPay\PosApiSdk\Exception\WebhookSearchLimitException;
use GoBTCPay\PosApiSdk\GoBTCPayServer;
use GoBTCPay\PosApiSdk\Tests\Support\FakeHttpClient;
use GoBTCPay\PosApiSdk\Tests\Support\Responses;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * `createWebhook()`, `listWebhooks()`, `findWebhookByUrl()` and `testWebhook()`
 * — endpoint management from the shop.
 */
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

    // ------------------------------------------------------------------
    // listWebhooks(): page-size bounds (1..100)
    // ------------------------------------------------------------------

    /**
     * The platform accepts a page size of 1..100, so a request outside that
     * range is brought inside it rather than sent through to be refused.
     *
     * @return array<string, array{int, int}>
     */
    public static function pageSizeBounds(): array
    {
        return [
            'zero goes up to the minimum' => [0, 1],
            'negative goes up to the minimum' => [-25, 1],
            'the minimum is kept' => [1, 1],
            'an ordinary size is kept' => [37, 37],
            'the maximum is kept' => [100, 100],
            'one above the maximum comes down' => [101, 100],
            'far above the maximum comes down' => [PHP_INT_MAX, 100],
        ];
    }

    #[DataProvider('pageSizeBounds')]
    public function testListWebhooksKeepsThePageSizeWithinOneToOneHundred(int $requested, int $expected): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['items' => [], 'totalCount' => 0]));

        $this->client($http)->listWebhooks(limit: $requested);

        self::assertSame($expected, $http->lastRequestJson()['pagination']['limit']);
    }

    // ------------------------------------------------------------------
    // createWebhook()
    // ------------------------------------------------------------------

    /**
     * Scenario "Подключение магазина регистрирует свой endpoint": the created
     * endpoint comes back together with its signing secret.
     */
    public function testCreateWebhookReturnsTheEndpointTogetherWithItsSigningSecret(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['webhook' => Responses::createdWebhook(signingSecret: 'whsec_abc123')]));

        $created = $this->client($http)->createWebhook('https://shop.example.com/?wc-api=gobtcpay');

        self::assertInstanceOf(WebhookEndpointWithSecret::class, $created);
        self::assertSame('whsec_abc123', $created->signingSecret);
        self::assertSame('wh_1', $created->id);
        self::assertSame('https://shop.example.com/?wc-api=gobtcpay', $created->url);
        self::assertSame('active', $created->status);
        self::assertSame(['payment.status.updated'], $created->events);

        $request = $http->lastRequest();
        self::assertNotNull($request);
        self::assertStringEndsWith('/merchant/webhook/create', (string) $request->getUri());
        self::assertSame('Bearer sk_live_secret', $request->getHeaderLine('Authorization'));
    }

    /**
     * Scenario "Область подписки не передана": the platform gets a merchant-level
     * registration, not a request with the scope missing — and the endpoint it
     * returns carries the merchant scope.
     */
    public function testCreateWebhookWithoutAScopeRegistersAtMerchantLevel(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['webhook' => Responses::createdWebhook()]));

        $created = $this->client($http)->createWebhook('https://shop.example.com/hook');

        $body = $http->lastRequestJson();
        self::assertSame('https://shop.example.com/hook', $body['url']);
        self::assertSame(['type' => 'merchant'], $body['scope']);
        self::assertTrue($created->scope->isMerchantWide());
    }

    /** Optional fields the caller did not give are left out of the request, not sent as null. */
    public function testCreateWebhookOmitsEventsAndLabelWhenNotGiven(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['webhook' => Responses::createdWebhook()]));

        $this->client($http)->createWebhook('https://shop.example.com/hook');

        $body = $http->lastRequestJson();
        self::assertArrayNotHasKey('events', $body);
        self::assertArrayNotHasKey('label', $body);
    }

    public function testCreateWebhookSendsEventsAndLabelWhenGiven(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['webhook' => Responses::createdWebhook()]));

        $this->client($http)->createWebhook(
            'https://shop.example.com/hook',
            events: [WebhookEvent::TYPE_PAYMENT_STATUS_UPDATED],
            label: 'My shop',
        );

        $body = $http->lastRequestJson();
        self::assertSame(['payment.status.updated'], $body['events']);
        self::assertSame('My shop', $body['label']);
    }

    /**
     * Each scope type goes out with exactly the identifier that names its
     * target: a store scope its store, a POS scope its terminal.
     *
     * @return array<string, array{WebhookScope, array<string, string>}>
     */
    public static function scopesOnTheWire(): array
    {
        return [
            'merchant' => [new WebhookScope(WebhookScope::TYPE_MERCHANT), ['type' => 'merchant']],
            'store' => [
                new WebhookScope(WebhookScope::TYPE_STORE, storeId: 'store_9'),
                ['type' => 'store', 'storeId' => 'store_9'],
            ],
            'pos' => [
                new WebhookScope(WebhookScope::TYPE_POS, posTerminalId: 'term_9'),
                ['type' => 'pos', 'posTerminalId' => 'term_9'],
            ],
        ];
    }

    /**
     * @param array<string, string> $expectedScope
     */
    #[DataProvider('scopesOnTheWire')]
    public function testCreateWebhookSerialisesTheGivenScope(WebhookScope $scope, array $expectedScope): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success(['webhook' => Responses::createdWebhook()]));

        $this->client($http)->createWebhook('https://shop.example.com/hook', $scope);

        self::assertSame($expectedScope, $http->lastRequestJson()['scope']);
    }

    /**
     * A store scope without a store, or a POS scope without a terminal, names
     * nothing. It is refused before the platform is asked: the fake client must
     * not have seen a single request.
     *
     * @return array<string, array{WebhookScope}>
     */
    public static function incompleteScopes(): array
    {
        return [
            'store without storeId' => [new WebhookScope(WebhookScope::TYPE_STORE)],
            'pos without posTerminalId' => [new WebhookScope(WebhookScope::TYPE_POS)],
        ];
    }

    #[DataProvider('incompleteScopes')]
    public function testCreateWebhookRefusesAnIncompleteScopeBeforeAnyRequest(WebhookScope $scope): void
    {
        $http = new FakeHttpClient();
        // Queued so that a request, were one made, would get an answer and the
        // failure would show up as the assertion on the request count below,
        // not as a different exception.
        $http->queueResponse(Responses::success(['webhook' => Responses::createdWebhook()]));

        try {
            $this->client($http)->createWebhook('https://shop.example.com/hook', $scope);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertCount(0, $http->requests, 'the incomplete scope must be refused before any request');
    }

    /**
     * A response that does not hold an endpoint is not a success: the secret
     * would be lost, and the caller would carry on with nothing.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function responsesWithoutAnEndpoint(): array
    {
        return [
            'no webhook key' => [[]],
            'webhook is null' => [['webhook' => null]],
            'webhook is a string' => [['webhook' => 'wh_1']],
        ];
    }

    /**
     * @param array<string, mixed> $success
     */
    #[DataProvider('responsesWithoutAnEndpoint')]
    public function testCreateWebhookThrowsWhenTheResponseHoldsNoEndpoint(array $success): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success($success));

        $this->expectException(GoBTCPayException::class);
        $this->client($http)->createWebhook('https://shop.example.com/hook');
    }

    /**
     * Scenario "URL отклонён политикой платформы": a request error, not a
     * result — and the machine-readable `webhook_url_*` code reaches the caller
     * as the exact string the platform sent.
     *
     * @return array<string, array{string}>
     */
    public static function rejectedUrlCodes(): array
    {
        return [
            'private host' => ['webhook_url_private_host'],
            'not https' => ['webhook_url_not_https'],
        ];
    }

    #[DataProvider('rejectedUrlCodes')]
    public function testARejectedUrlSurfacesItsMachineCodeUnchanged(string $code): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::failure(400, message: 'URL rejected', data: ['type' => $code]));

        try {
            $this->client($http)->createWebhook('http://10.0.0.5/hook');
            self::fail('expected the rejected URL to surface as an error');
        } catch (ValidationException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame($code, $e->type());
        }
    }

    /**
     * Scenario "Ключ ограничен магазином": a permission error — and the SDK
     * must not suggest the refusal can be told apart from a revoked or unknown
     * key. The contract has no machine-readable marker for that, so the three
     * refusals below differ only in their message text; the test sends three
     * different texts and requires the identical machine-visible outcome. It
     * never looks at the text.
     *
     * @return array<string, array{string}>
     */
    public static function refusalsThatDifferOnlyInMessage(): array
    {
        return [
            'store-limited key' => ['This API key is limited to a single store'],
            'revoked key' => ['API key revoked'],
            'unknown key' => ['Unknown API key'],
        ];
    }

    #[DataProvider('refusalsThatDifferOnlyInMessage')]
    public function testAStoreLimitedKeyIsRefusedWithExactlyAnAuthExceptionAndNoMachineMarker(string $message): void
    {
        $e = $this->refusalFor($message);

        // The exact class, not "an AuthException or any subclass of it": a
        // dedicated subclass for the store-limited case would be a promise that
        // this refusal can be told apart, which the contract does not make.
        self::assertSame(AuthException::class, $e::class);
        self::assertSame(403, $e->httpStatus);
        // No machine-readable discriminator: the platform sent none, and the
        // SDK must not invent one.
        self::assertNull($e->type());
    }

    /**
     * The three refusals above differ only in their message text, so nothing a
     * caller can read off the exception except that text may differ between
     * them: same class, and the same public state once the text is set aside
     * (the message itself, and its copy inside the decoded body). A subclass or
     * an extra public field that told them apart would show up here.
     */
    public function testRefusalsOfDifferentCausesAreIndistinguishableApartFromTheirText(): void
    {
        $seen = [];
        foreach (self::refusalsThatDifferOnlyInMessage() as $label => [$message]) {
            $e = $this->refusalFor($message);
            $vars = get_object_vars($e);
            self::assertIsArray($vars['body']);
            unset($vars['body']['message']);
            $seen[$label] = ['class' => $e::class, 'vars' => $vars];
        }

        self::assertCount(3, $seen);
        $reference = reset($seen);
        foreach ($seen as $label => $actual) {
            self::assertSame(AuthException::class, $actual['class'], $label);
            self::assertEquals($reference['vars'], $actual['vars'], $label);
        }
    }

    private function refusalFor(string $message): AuthException
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::failure(403, message: $message));

        try {
            $this->client($http)->createWebhook('https://shop.example.com/hook');
        } catch (AuthException $e) {
            self::assertCount(1, $http->requests);

            return $e;
        }

        self::fail('expected the 403 to surface');
    }

    /**
     * Scenario "Запрос создания не дошёл до ответа": after a network error the
     * outcome is unknown, so the SDK raises and does not repeat — the platform
     * sees at most one create request per call.
     */
    public function testCreateWebhookIsNotRepeatedAfterANetworkError(): void
    {
        $http = new FakeHttpClient();
        $http->queueException(Responses::networkException('Connection reset by peer'));
        // A second attempt, were one made, would find this and succeed — the
        // request count below is what proves it did not happen.
        $http->queueResponse(Responses::success(['webhook' => Responses::createdWebhook()]));

        try {
            $this->client($http)->createWebhook('https://shop.example.com/hook');
            self::fail('expected the network error to surface');
        } catch (NetworkException) {
            // expected
        }

        self::assertCount(1, $http->requests);
        self::assertStringEndsWith('/merchant/webhook/create', (string) $http->requests[0]->getUri());
    }

    /** The same holds for a retryable server error: one attempt, then the error. */
    public function testCreateWebhookIsNotRepeatedAfterAServerError(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::failure(503, message: 'upstream down'));
        $http->queueResponse(Responses::success(['webhook' => Responses::createdWebhook()]));

        try {
            $this->client($http)->createWebhook('https://shop.example.com/hook');
            self::fail('expected the 503 to surface');
        } catch (ApiException $e) {
            self::assertSame(503, $e->httpStatus);
        }

        self::assertCount(1, $http->requests);
    }

    // ------------------------------------------------------------------
    // WebhookEndpointWithSecret vs WebhookEndpoint
    // ------------------------------------------------------------------

    /**
     * Requirement "Паритет": the field set of the creation result is the
     * listing item's plus exactly `signingSecret`, so the two classes cannot
     * drift apart unnoticed.
     */
    public function testCreationResultHasTheListingFieldsPlusExactlySigningSecret(): void
    {
        $names = static fn (string $class): array => array_map(
            static fn (ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC),
        );

        $listing = $names(WebhookEndpoint::class);
        $creation = $names(WebhookEndpointWithSecret::class);
        sort($listing);
        sort($creation);

        $expected = [...$listing, 'signingSecret'];
        sort($expected);

        self::assertSame($expected, $creation);
    }

    /** Requirement "Секрет подписи виден только в результате создания": a listing item has no secret to read. */
    public function testAListingItemCarriesNoSigningSecret(): void
    {
        self::assertFalse(property_exists(WebhookEndpoint::class, 'signingSecret'));

        $http = new FakeHttpClient();
        // Even if a platform answered with a secret on a listing row, the item type has nowhere to put it.
        $http->queueResponse(Responses::success([
            'items' => [Responses::webhookEndpoint() + ['signingSecret' => 'whsec_leak']],
            'totalCount' => 1,
        ]));

        $item = $this->client($http)->listWebhooks()['items'][0];

        self::assertFalse(property_exists($item, 'signingSecret'));
    }

    /** Converting the creation result to a plain endpoint keeps every shared field and drops the secret. */
    public function testToEndpointKeepsTheSharedFieldsAndDropsTheSecret(): void
    {
        $data = Responses::createdWebhook(scopeType: 'store');

        $plain = WebhookEndpointWithSecret::fromArray($data)->toEndpoint();

        self::assertEquals(WebhookEndpoint::fromArray($data), $plain);
        self::assertFalse(property_exists($plain, 'signingSecret'));
    }

    // ------------------------------------------------------------------
    // findWebhookByUrl()
    // ------------------------------------------------------------------

    private const TARGET_URL = 'https://shop.example.com/?wc-api=gobtcpay';

    /** Requirement "Поиск обходит листинг": the endpoint is on the third page, so the first two must be read. */
    public function testFindWebhookByUrlFindsAnEndpointOnTheThirdPage(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::webhookPage(100, page: 0, totalCount: 250));
        $http->queueResponse(Responses::webhookPage(100, page: 1, totalCount: 250));
        $http->queueResponse(Responses::webhookPage(
            50,
            page: 2,
            totalCount: 250,
            lastRow: Responses::webhookEndpoint(id: 'wh_target', url: self::TARGET_URL),
        ));

        $found = $this->client($http)->findWebhookByUrl(self::TARGET_URL);

        self::assertNotNull($found);
        self::assertSame('wh_target', $found->id);
        self::assertSame(self::TARGET_URL, $found->url);
        self::assertCount(3, $http->requests);

        $skips = array_map(
            static fn ($r): int => json_decode((string) $r->getBody(), true)['pagination']['skip'],
            $http->requests,
        );
        self::assertSame([0, 100, 200], $skips);
    }

    public function testFindWebhookByUrlStopsAtOnceWhenTheFirstPageHoldsTheUrl(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::webhookPage(
            100,
            totalCount: 100,
            lastRow: Responses::webhookEndpoint(id: 'wh_target', url: self::TARGET_URL),
        ));

        $found = $this->client($http)->findWebhookByUrl(self::TARGET_URL);

        self::assertSame('wh_target', $found?->id);
        self::assertCount(1, $http->requests);
    }

    /** Pages are read 100 at a time, with no status filter — an endpoint is found whatever its status. */
    public function testFindWebhookByUrlReadsFullPagesWithoutAStatusFilter(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::webhookPage(0));

        $this->client($http)->findWebhookByUrl(self::TARGET_URL);

        $body = $http->lastRequestJson();
        self::assertSame(100, $body['pagination']['limit']);
        self::assertSame(0, $body['pagination']['skip']);
        self::assertSame([], $body['filters']);
    }

    /**
     * Scenario "Такого URL у мерчанта нет": an explicit null, not an error —
     * absence is the expected outcome of a first connection.
     */
    public function testFindWebhookByUrlReturnsNullWhenEveryPageWasReadAndTheUrlIsAbsent(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::webhookPage(100, page: 0, totalCount: 237));
        $http->queueResponse(Responses::webhookPage(100, page: 1, totalCount: 237));
        $http->queueResponse(Responses::webhookPage(37, page: 2, totalCount: 237));

        self::assertNull($this->client($http)->findWebhookByUrl(self::TARGET_URL));
        self::assertCount(3, $http->requests);
    }

    /** A merchant with no endpoints at all: the first page is empty and the answer is null. */
    public function testFindWebhookByUrlReturnsNullForAMerchantWithNoEndpoints(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::webhookPage(0));

        self::assertNull($this->client($http)->findWebhookByUrl(self::TARGET_URL));
        self::assertCount(1, $http->requests);
    }

    /**
     * The URL is compared for exact equality, not normalised: a trailing slash
     * or a different case is a different URL.
     *
     * @return array<string, array{string}>
     */
    public static function almostTheSameUrl(): array
    {
        return [
            'trailing slash added' => [self::TARGET_URL . '/'],
            'different case' => [strtoupper(self::TARGET_URL)],
            'different query' => ['https://shop.example.com/?wc-api=other'],
        ];
    }

    #[DataProvider('almostTheSameUrl')]
    public function testFindWebhookByUrlComparesTheUrlExactly(string $registered): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::webhookPage(
            3,
            lastRow: Responses::webhookEndpoint(id: 'wh_other', url: $registered),
        ));

        self::assertNull($this->client($http)->findWebhookByUrl(self::TARGET_URL));
    }

    /**
     * Scenario "Листинг оказался длиннее предела обхода", first half. Twenty
     * full pages and the URL on none of them: the search stops at its limit and
     * raises, and it does not ask for a twenty-first page. The exception, not
     * `null`, is what tells the caller the answer is unknown.
     */
    public function testFindWebhookByUrlThrowsWhenTwentyFullPagesDidNotHoldTheUrl(): void
    {
        $http = new FakeHttpClient();
        for ($page = 0; $page < 20; $page++) {
            $http->queueResponse(Responses::webhookPage(100, page: $page, totalCount: 5000));
        }
        // A twenty-first request would be answered, not refused: the request
        // count below is what shows the search really stopped.
        $http->queueResponse(Responses::webhookPage(
            1,
            page: 20,
            totalCount: 5000,
            lastRow: Responses::webhookEndpoint(id: 'wh_too_late', url: self::TARGET_URL),
        ));

        try {
            $this->client($http)->findWebhookByUrl(self::TARGET_URL);
            self::fail('expected WebhookSearchLimitException');
        } catch (WebhookSearchLimitException $e) {
            self::assertSame(20, $e->pagesScanned);
            self::assertSame(2000, $e->scannedCount);
            self::assertSame(5000, $e->totalCount);
        }

        self::assertCount(20, $http->requests);
    }

    /** The exception is a GoBTCPayException and not an API error: no response was a failure. */
    public function testTheSearchLimitExceptionIsNotAnApiError(): void
    {
        $e = new WebhookSearchLimitException(20, 2000, 5000);

        self::assertInstanceOf(GoBTCPayException::class, $e);
        self::assertNotInstanceOf(ApiException::class, $e);
    }

    /**
     * The boundary pair. These two tests differ in one thing only — whether the
     * last permitted page is full — and that difference is the requirement:
     * a full last page means more endpoints may follow (unknown), a short one
     * means the listing ended (not registered).
     *
     * Last permitted page with EXACTLY 100 items and no match: unknown.
     */
    public function testALastPermittedPageOfExactlyOneHundredWithoutTheUrlThrows(): void
    {
        $http = new FakeHttpClient();
        for ($page = 0; $page < 19; $page++) {
            $http->queueResponse(Responses::webhookPage(100, page: $page, totalCount: 2100));
        }
        $http->queueResponse(Responses::webhookPage(100, page: 19, totalCount: 2100));

        $this->expectException(WebhookSearchLimitException::class);
        try {
            $this->client($http)->findWebhookByUrl(self::TARGET_URL);
        } finally {
            self::assertCount(20, $http->requests);
        }
    }

    /** Last permitted page with FEWER than 100 items and no match: the listing ended, not registered. */
    public function testALastPermittedPageOfFewerThanOneHundredWithoutTheUrlReturnsNull(): void
    {
        $http = new FakeHttpClient();
        for ($page = 0; $page < 19; $page++) {
            $http->queueResponse(Responses::webhookPage(100, page: $page, totalCount: 1999));
        }
        $http->queueResponse(Responses::webhookPage(99, page: 19, totalCount: 1999));

        self::assertNull($this->client($http)->findWebhookByUrl(self::TARGET_URL));
        self::assertCount(20, $http->requests);
    }

    /** The URL on the very last permitted page, itself full, is found — the limit is on pages read, not a refusal to look. */
    public function testTheUrlOnAFullLastPermittedPageIsFound(): void
    {
        $http = new FakeHttpClient();
        for ($page = 0; $page < 19; $page++) {
            $http->queueResponse(Responses::webhookPage(100, page: $page, totalCount: 2000));
        }
        $http->queueResponse(Responses::webhookPage(
            100,
            page: 19,
            totalCount: 2000,
            lastRow: Responses::webhookEndpoint(id: 'wh_target', url: self::TARGET_URL),
        ));

        $found = $this->client($http)->findWebhookByUrl(self::TARGET_URL);

        self::assertSame('wh_target', $found?->id);
        self::assertCount(20, $http->requests);
    }

    /**
     * The server computes `totalCount` separately from the page, so it can be
     * understated. The search stops on the length of a page, not on that
     * number: here the server says 100 in total on every page, yet the URL is
     * on the third. Trusting the total would stop after page one and report
     * "not found" — after which a caller creates a second endpoint.
     */
    public function testAnUnderstatedTotalCountDoesNotEndTheSearchEarly(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::webhookPage(100, page: 0, totalCount: 100));
        $http->queueResponse(Responses::webhookPage(100, page: 1, totalCount: 100));
        $http->queueResponse(Responses::webhookPage(
            40,
            page: 2,
            totalCount: 100,
            lastRow: Responses::webhookEndpoint(id: 'wh_target', url: self::TARGET_URL),
        ));

        $found = $this->client($http)->findWebhookByUrl(self::TARGET_URL);

        self::assertSame('wh_target', $found?->id);
        self::assertCount(3, $http->requests);
    }

    /** Same understatement, URL absent: the search runs on to the short page and only then answers null. */
    public function testAnUnderstatedTotalCountDoesNotTurnAnAbsentUrlIntoAnEarlyNull(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::webhookPage(100, page: 0, totalCount: 1));
        $http->queueResponse(Responses::webhookPage(100, page: 1, totalCount: 1));
        $http->queueResponse(Responses::webhookPage(10, page: 2, totalCount: 1));

        self::assertNull($this->client($http)->findWebhookByUrl(self::TARGET_URL));
        self::assertCount(3, $http->requests);
    }

    /** An overstated total does not keep the search going past a short page either: the page length is the only stop. */
    public function testAnOverstatedTotalCountDoesNotKeepTheSearchGoingPastAShortPage(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::webhookPage(10, page: 0, totalCount: 5000));

        self::assertNull($this->client($http)->findWebhookByUrl(self::TARGET_URL));
        self::assertCount(1, $http->requests);
    }

    /** A refusal on a listing request is not swallowed into null: the caller must not read "refused" as "absent". */
    public function testFindWebhookByUrlSurfacesARefusal(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::failure(403, message: 'refused'));

        $this->expectException(AuthException::class);
        $this->client($http)->findWebhookByUrl(self::TARGET_URL);
    }

    /** A failure on a later page does not become a "not found" built from the pages read so far. */
    public function testAFailureOnALaterPageIsNotTurnedIntoNull(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::webhookPage(100, page: 0));
        $http->queueResponse(Responses::failure(400, message: 'bad request'));

        $this->expectException(ValidationException::class);
        $this->client($http)->findWebhookByUrl(self::TARGET_URL);
    }

    // ------------------------------------------------------------------
    // testWebhook(): the platform must confirm the delivery was queued
    // ------------------------------------------------------------------

    /**
     * Requirement "Запрос тестовой доставки не утверждает доставку": success is
     * returned only when the platform confirmed the queueing. A response that
     * does not say so — `ok` missing, or not boolean true — raises.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unconfirmedTestResponses(): array
    {
        return [
            'ok false' => [['ok' => false]],
            'ok missing' => [[]],
            'ok null' => [['ok' => null]],
            'ok is the string "true"' => [['ok' => 'true']],
            'ok is the integer 1' => [['ok' => 1]],
        ];
    }

    /**
     * @param array<string, mixed> $success
     */
    #[DataProvider('unconfirmedTestResponses')]
    public function testTestWebhookDoesNotReturnSuccessWithoutConfirmation(array $success): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(Responses::success($success));

        $this->expectException(GoBTCPayException::class);
        $this->client($http)->testWebhook('wh_1');
    }
}
