<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Unit;

use GoBTCPay\PosApiSdk\Dto\WebhookEvent;
use GoBTCPay\PosApiSdk\Exception\WebhookSignatureException;
use GoBTCPay\PosApiSdk\Signing;
use GoBTCPay\PosApiSdk\WebhookHandler;
use PHPUnit\Framework\TestCase;

final class WebhookHandlerTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private function body(string $eventId = 'evt_1', string $status = 'paid'): string
    {
        return json_encode([
            'eventId' => $eventId,
            'type' => WebhookEvent::TYPE_PAYMENT_STATUS_UPDATED,
            'createdAt' => 1_700_000_000,
            'data' => ['paymentId' => 'pay_1', 'status' => $status],
        ], JSON_THROW_ON_ERROR);
    }

    private function header(string $body, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();
        $v1 = Signing::computeWebhookSignature(self::SECRET, $t, $body);

        return "t={$t},v1={$v1}";
    }

    public function testValidSignaturePasses(): void
    {
        $handler = new WebhookHandler(self::SECRET);
        $body = $this->body();

        $event = $handler->constructEvent($body, $this->header($body));

        self::assertSame('evt_1', $event->eventId);
        self::assertSame(WebhookEvent::TYPE_PAYMENT_STATUS_UPDATED, $event->type);
        self::assertSame('pay_1', $event->payment()->paymentId);
    }

    public function testInvalidSignatureThrows(): void
    {
        $handler = new WebhookHandler(self::SECRET);
        $body = $this->body();
        $t = time();

        $this->expectException(WebhookSignatureException::class);
        $handler->constructEvent($body, "t={$t},v1=" . str_repeat('0', 64));
    }

    public function testMissingHeaderThrows(): void
    {
        $handler = new WebhookHandler(self::SECRET);

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Missing');
        $handler->constructEvent($this->body(), null);
    }

    public function testMalformedHeaderThrows(): void
    {
        $handler = new WebhookHandler(self::SECRET);

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Malformed');
        $handler->constructEvent($this->body(), 'garbage-without-parts');
    }

    public function testExpiredTimestampThrows(): void
    {
        $handler = new WebhookHandler(self::SECRET, toleranceSeconds: 300);
        $body = $this->body();
        $old = time() - 10_000;

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('tolerance');
        $handler->constructEvent($body, $this->header($body, $old));
    }

    public function testToleranceZeroDisablesReplayCheck(): void
    {
        $handler = new WebhookHandler(self::SECRET, toleranceSeconds: 0);
        $body = $this->body();
        $old = time() - 1_000_000;

        $event = $handler->constructEvent($body, $this->header($body, $old));
        self::assertSame('evt_1', $event->eventId);
    }

    public function testDedupReturnsNullOnSecondCall(): void
    {
        $handler = new WebhookHandler(self::SECRET);
        $body = $this->body();
        $header = $this->header($body);

        $first = $handler->handle($body, $header);
        $second = $handler->handle($body, $header);

        self::assertInstanceOf(WebhookEvent::class, $first);
        self::assertNull($second);
    }

    public function testListenerDispatch(): void
    {
        $handler = new WebhookHandler(self::SECRET);
        $received = [];
        $handler->on(WebhookEvent::TYPE_PAYMENT_STATUS_UPDATED, function (WebhookEvent $event) use (&$received): void {
            $received[] = $event->data['status'] ?? null;
        });

        $body = $this->body(status: 'paid');
        $handler->handle($body, $this->header($body));

        self::assertSame(['paid'], $received);
    }

    public function testUnsubscribeStopsDispatch(): void
    {
        $handler = new WebhookHandler(self::SECRET);
        $count = 0;
        $off = $handler->on(WebhookEvent::TYPE_PAYMENT_STATUS_UPDATED, function () use (&$count): void {
            $count++;
        });

        $b1 = $this->body(eventId: 'e1');
        $handler->handle($b1, $this->header($b1));
        $off();
        $b2 = $this->body(eventId: 'e2');
        $handler->handle($b2, $this->header($b2));

        self::assertSame(1, $count);
    }

    public function testDedupCacheEvictsOldestWhenFull(): void
    {
        $handler = new WebhookHandler(self::SECRET, dedupeCacheSize: 2);

        foreach (['a', 'b', 'c'] as $id) {
            $b = $this->body(eventId: $id);
            self::assertInstanceOf(WebhookEvent::class, $handler->handle($b, $this->header($b)));
        }

        // 'a' was evicted (cache size 2), so it is treated as new again.
        $ba = $this->body(eventId: 'a');
        self::assertInstanceOf(WebhookEvent::class, $handler->handle($ba, $this->header($ba)));

        // 'c' is still cached, so it de-dupes to null.
        $bc = $this->body(eventId: 'c');
        self::assertNull($handler->handle($bc, $this->header($bc)));
    }
}
