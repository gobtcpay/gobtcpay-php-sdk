<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Unit;

use GoBTCPay\PosApiSdk\Exception\AuthException;
use GoBTCPay\PosApiSdk\Exception\NetworkException;
use GoBTCPay\PosApiSdk\Exception\NotFoundException;
use GoBTCPay\PosApiSdk\Exception\RateLimitException;
use GoBTCPay\PosApiSdk\Exception\ServerException;
use GoBTCPay\PosApiSdk\Exception\ValidationException;
use GoBTCPay\PosApiSdk\Tests\Support\FakeHttpClient;
use GoBTCPay\PosApiSdk\Tests\Support\Responses;
use GoBTCPay\PosApiSdk\Transport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private function transport(
        FakeHttpClient $client,
        string $auth = Transport::AUTH_BEARER,
        int $maxRetries = 2,
    ): Transport {
        $factory = new Psr17Factory();

        return new Transport(
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            authStrategy: $auth,
            apiKey: $auth === Transport::AUTH_HMAC ? 'terminal-secret' : 'sk_live_abc',
            baseUrl: 'https://api.example.com/public/api/v1.2',
            timeoutMs: 5000,
            maxRetries: $maxRetries,
        );
    }

    public function testSuccessfulRequestUnwrapsEnvelope(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(Responses::success(Responses::samplePayment()));

        $result = $this->transport($client)->post('/merchant/payment/get', ['paymentId' => 'pay_1']);

        self::assertSame('pay_1', $result['paymentId']);
        self::assertSame('initiated', $result['status']);
    }

    public function testBearerAuthSetsAuthorizationHeaderAndDoesNotSign(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(Responses::success(Responses::samplePayment()));

        $this->transport($client)->post('/merchant/payment/get', ['paymentId' => 'pay_1']);

        $request = $client->lastRequest();
        self::assertNotNull($request);
        self::assertSame('Bearer sk_live_abc', $request->getHeaderLine('Authorization'));
        self::assertStringContainsString('gobtcpay-pos-api-sdk-php', $request->getHeaderLine('User-Agent'));
        self::assertArrayNotHasKey('signature', $client->lastRequestJson());
    }

    public function testHmacAuthSignsBodyWithTsAndSignature(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(Responses::success(Responses::samplePayment()));

        $this->transport($client, Transport::AUTH_HMAC)
            ->post('/pos/transaction/get-payment', ['paymentId' => 'pay_1']);

        $body = $client->lastRequestJson();
        self::assertArrayHasKey('ts', $body);
        self::assertArrayHasKey('signature', $body);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $body['signature']);
        self::assertSame('', $client->lastRequest()?->getHeaderLine('Authorization'));
    }

    public function testFailureEnvelopeThrowsValidationExceptionOn400(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(Responses::failure(400, 1001, 'bad amount', ['type' => 'validation_error']));

        try {
            $this->transport($client)->post('/merchant/payment/create', ['amount' => -1]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('bad amount', $e->getMessage());
            self::assertSame('validation_error', $e->type());
        }
    }

    public function testRetriesOn5xxThenSucceeds(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(Responses::failure(503, message: 'unavailable'));
        $client->queueResponse(Responses::success(Responses::samplePayment(status: 'paid')));

        $result = $this->transport($client, maxRetries: 2)
            ->post('/merchant/payment/get', ['paymentId' => 'pay_1'], retry: true);

        self::assertSame('paid', $result['status']);
        self::assertCount(2, $client->requests);
    }

    public function testNoRetryWhenMaxRetriesZero(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(Responses::failure(503, message: 'unavailable'));

        $this->expectException(ServerException::class);
        try {
            $this->transport($client, maxRetries: 0)
                ->post('/merchant/payment/get', ['paymentId' => 'pay_1'], retry: true);
        } finally {
            self::assertCount(1, $client->requests);
        }
    }

    public function testNoRetryWhenRetryFlagFalse(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(Responses::failure(503, message: 'unavailable'));

        try {
            $this->transport($client, maxRetries: 5)
                ->post('/merchant/payment/create', ['amount' => 10], retry: false);
            self::fail('Expected ServerException');
        } catch (ServerException) {
            self::assertCount(1, $client->requests);
        }
    }

    public function testRateLimitThrowsRateLimitExceptionWithRetryAfter(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(Responses::failure(429, message: 'slow down', headers: ['retry-after' => '2']));

        try {
            $this->transport($client, maxRetries: 0)->post('/merchant/payment/get', ['paymentId' => 'x'], retry: true);
            self::fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(429, $e->httpStatus);
            self::assertSame(2000, $e->retryAfterMs);
        }
    }

    public function testAuthExceptionOn401And403(): void
    {
        foreach ([401, 403] as $status) {
            $client = new FakeHttpClient();
            $client->queueResponse(Responses::failure($status, message: 'nope'));
            try {
                $this->transport($client)->post('/merchant/payment/get', ['paymentId' => 'x']);
                self::fail('Expected AuthException');
            } catch (AuthException $e) {
                self::assertSame($status, $e->httpStatus);
            }
        }
    }

    public function testNotFoundExceptionOn404(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(Responses::failure(404, message: 'no such payment'));

        $this->expectException(NotFoundException::class);
        $this->transport($client)->post('/merchant/payment/get', ['paymentId' => 'missing']);
    }

    public function testNetworkErrorThrowsNetworkExceptionAndRetries(): void
    {
        $client = new FakeHttpClient();
        $client->queueException(Responses::networkException('connection refused'));
        $client->queueResponse(Responses::success(Responses::samplePayment()));

        $result = $this->transport($client, maxRetries: 1)
            ->post('/merchant/payment/get', ['paymentId' => 'pay_1'], retry: true);

        self::assertSame('pay_1', $result['paymentId']);
        self::assertCount(2, $client->requests);
    }

    public function testTimeoutNetworkExceptionIsFlaggedAsTimeout(): void
    {
        $client = new FakeHttpClient();
        $client->queueException(Responses::networkException('Operation timed out'));

        try {
            $this->transport($client, maxRetries: 0)
                ->post('/merchant/payment/get', ['paymentId' => 'x'], retry: true);
            self::fail('Expected NetworkException');
        } catch (NetworkException $e) {
            self::assertTrue($e->isTimeout);
        }
    }

    public function testOnEventCallbackFiresPerAttempt(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(Responses::success(Responses::samplePayment()));

        $events = [];
        $factory = new Psr17Factory();
        $transport = new Transport(
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            authStrategy: Transport::AUTH_BEARER,
            apiKey: 'sk_live_abc',
            baseUrl: 'https://api.example.com/v1.2',
            onEvent: function (array $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $transport->post('/merchant/payment/get', ['paymentId' => 'pay_1']);

        self::assertCount(1, $events);
        self::assertSame(200, $events[0]['httpStatus']);
        self::assertFalse($events[0]['willRetry']);
    }
}
