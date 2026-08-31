<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Unit;

use GoBTCPay\PosApiSdk\Dto\Payment;
use GoBTCPay\PosApiSdk\Dto\PaymentStatus;
use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;
use GoBTCPay\PosApiSdk\PaymentPoller;
use GoBTCPay\PosApiSdk\Tests\Support\Responses;
use PHPUnit\Framework\TestCase;

final class PaymentPollerTest extends TestCase
{
    /**
     * @param list<string> $statuses status returned by each successive poll
     *
     * @return callable(string): Payment
     */
    private function fetcher(array $statuses): callable
    {
        $i = 0;

        return function (string $paymentId) use (&$i, $statuses): Payment {
            $status = $statuses[min($i, count($statuses) - 1)];
            $i++;

            return Payment::fromArray(Responses::samplePayment(status: $status, paymentId: $paymentId));
        };
    }

    /**
     * Poller that never actually waits (records requested sleeps instead), so
     * tests run instantly. A tiny real sleep keeps wall-clock advancing for the
     * timeout test.
     *
     * @param callable(string): Payment $fetcher
     * @param array<string, mixed> $options
     */
    private function poller(callable $fetcher, array $options): object
    {
        return new class ($fetcher, $options) extends PaymentPoller {
            /** @var list<int> */
            public array $sleeps = [];

            protected function sleepMs(int $ms): void
            {
                $this->sleeps[] = $ms;
                usleep(1000); // 1ms, so timeout checks based on wall-clock still fire
            }
        };
    }

    public function testPollsUntilFinalStatus(): void
    {
        $poller = $this->poller(
            $this->fetcher(['initiated', 'detected', 'paid']),
            ['paymentId' => 'pay_1'],
        );

        $final = $poller->poll();

        self::assertSame(PaymentStatus::Paid, $final->status);
        self::assertSame(3, $poller->attempts());
    }

    public function testStopsImmediatelyWhenFirstPollIsFinal(): void
    {
        $poller = $this->poller(
            $this->fetcher(['canceled']),
            ['paymentId' => 'pay_1'],
        );

        $final = $poller->poll();

        self::assertSame(PaymentStatus::Canceled, $final->status);
        self::assertSame(1, $poller->attempts());
        // immediate=true: no sleep before the first (and only) poll.
        self::assertSame([], $poller->sleeps);
    }

    public function testRespectsMinimumInterval(): void
    {
        $poller = $this->poller(
            $this->fetcher(['initiated', 'paid']),
            ['paymentId' => 'pay_1', 'intervalMs' => 100],
        );

        $poller->poll();

        // Requested 100ms but clamped up to the 3000ms minimum.
        self::assertSame([PaymentPoller::MIN_POLL_INTERVAL_MS], $poller->sleeps);
    }

    public function testNonImmediateSleepsBeforeFirstPoll(): void
    {
        $poller = $this->poller(
            $this->fetcher(['paid']),
            ['paymentId' => 'pay_1', 'immediate' => false],
        );

        $poller->poll();

        self::assertSame([PaymentPoller::MIN_POLL_INTERVAL_MS], $poller->sleeps);
    }

    public function testCustomUntilStopsEarly(): void
    {
        $poller = $this->poller(
            $this->fetcher(['initiated', 'detected', 'paid']),
            ['paymentId' => 'pay_1', 'until' => [PaymentStatus::Detected]],
        );

        $final = $poller->poll();

        self::assertSame(PaymentStatus::Detected, $final->status);
        self::assertSame(2, $poller->attempts());
    }

    public function testCallbacksFire(): void
    {
        $poller = $this->poller(
            $this->fetcher(['initiated', 'paid']),
            ['paymentId' => 'pay_1'],
        );

        $changes = 0;
        $updates = 0;
        $paid = 0;
        $settled = 0;
        $poller->onChange(function () use (&$changes): void {
            $changes++;
        });
        $poller->onUpdate(function () use (&$updates): void {
            $updates++;
        });
        $poller->onPaid(function () use (&$paid): void {
            $paid++;
        });
        $poller->onSettled(function () use (&$settled): void {
            $settled++;
        });

        $poller->poll();

        self::assertSame(2, $changes);
        self::assertSame(2, $updates);
        self::assertSame(1, $paid);
        self::assertSame(1, $settled);
    }

    public function testStopOnErrorThrows(): void
    {
        $poller = $this->poller(
            function (): Payment {
                throw new GoBTCPayException('boom');
            },
            ['paymentId' => 'pay_1', 'stopOnError' => true],
        );

        $errors = 0;
        $poller->onError(function () use (&$errors): void {
            $errors++;
        });

        $this->expectException(GoBTCPayException::class);
        try {
            $poller->poll();
        } finally {
            self::assertSame(1, $errors);
        }
    }

    public function testTimeoutThrows(): void
    {
        $poller = $this->poller(
            $this->fetcher(['initiated']), // never settles
            ['paymentId' => 'pay_1', 'timeoutMs' => 3],
        );

        $this->expectException(GoBTCPayException::class);
        $this->expectExceptionMessage('timed out');
        $poller->poll();
    }
}
