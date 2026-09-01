<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk;

use GoBTCPay\PosApiSdk\Dto\Payment;
use GoBTCPay\PosApiSdk\Dto\PaymentStatus;
use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;

/**
 * Polls a fetcher on an interval until a payment reaches one of the statuses
 * it stops at.
 *
 * PHP request handlers are synchronous, so {@see poll} is a blocking loop: it
 * calls the fetcher, fires callbacks, sleeps for the interval, and repeats until
 * it stops (or a timeout / error stops it). Subscribe with
 * {@see onChange} / {@see onUpdate} / {@see onPaid} / {@see onSettled} /
 * {@see onError}.
 *
 * @phpstan-type Options array{
 *     paymentId: string,
 *     intervalMs?: int,
 *     timeoutMs?: int,
 *     immediate?: bool,
 *     until?: list<PaymentStatus>,
 *     stopOnError?: bool,
 * }
 */
class PaymentPoller
{
    /** Minimum (and default) polling interval, in milliseconds. */
    public const MIN_POLL_INTERVAL_MS = 3000;

    private readonly string $paymentId;
    private readonly int $intervalMs;
    private readonly int $timeoutMs;
    private readonly bool $immediate;
    private readonly bool $stopOnError;

    /** @var array<string, true> Set of stopping statuses, keyed by their string value. */
    private readonly array $until;

    private int $attempts = 0;
    private ?Payment $lastPayment = null;
    private PaymentStatus|string $lastStatus = 'idle';

    /** Fetches the payment by id: `fn(string $paymentId): Payment`. */
    private readonly \Closure $fetcher;

    /** @var list<callable> */
    private array $changeListeners = [];
    /** @var list<callable> */
    private array $updateListeners = [];
    /** @var list<callable> */
    private array $paidListeners = [];
    /** @var list<callable> */
    private array $settledListeners = [];
    /** @var list<callable> */
    private array $errorListeners = [];

    /**
     * @param callable(string): Payment $fetcher Fetches the payment by id.
     * @param Options $options
     */
    public function __construct(
        callable $fetcher,
        array $options,
    ) {
        $this->fetcher = \Closure::fromCallable($fetcher);
        $this->paymentId = $options['paymentId'];
        $this->intervalMs = max(
            self::MIN_POLL_INTERVAL_MS,
            $options['intervalMs'] ?? self::MIN_POLL_INTERVAL_MS,
        );
        $this->timeoutMs = $options['timeoutMs'] ?? 0;
        $this->immediate = $options['immediate'] ?? true;
        $this->stopOnError = $options['stopOnError'] ?? false;

        $until = $options['until'] ?? PaymentStatus::finalStatuses();
        $set = [];
        foreach ($until as $status) {
            $set[$status->value] = true;
        }
        $this->until = $set;
    }

    /** Subscribe to any status change. */
    public function onChange(callable $listener): self
    {
        $this->changeListeners[] = $listener;

        return $this;
    }

    /** Subscribe to every successful poll. */
    public function onUpdate(callable $listener): self
    {
        $this->updateListeners[] = $listener;

        return $this;
    }

    /** Subscribe to the transition into `paid` (fires once). */
    public function onPaid(callable $listener): self
    {
        $this->paidListeners[] = $listener;

        return $this;
    }

    /**
     * Subscribe to the status the poller stops at.
     *
     * Stopping is not the same as the outcome being decided: with the default
     * stop set this fires for `expired` too, and `expired` is NOT terminal on
     * the server — funds arriving within the grace period still move the
     * payment to `paid` afterwards. Do not close an order irreversibly from
     * here without checking the status you were handed.
     */
    public function onSettled(callable $listener): self
    {
        $this->settledListeners[] = $listener;

        return $this;
    }

    /** Subscribe to poll errors. */
    public function onError(callable $listener): self
    {
        $this->errorListeners[] = $listener;

        return $this;
    }

    /** Number of poll attempts made so far. */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /** The most recent payment snapshot, or null before the first successful poll. */
    public function lastPayment(): ?Payment
    {
        return $this->lastPayment;
    }

    /**
     * Blocking poll loop. Returns the payment once it reaches a stopping status.
     *
     * @throws GoBTCPayException on timeout, or on the first error when `stopOnError` is set.
     */
    public function poll(): Payment
    {
        $startedAt = microtime(true);
        $first = true;

        for (;;) {
            if (!$first || !$this->immediate) {
                $this->sleepMs($this->intervalMs);
            }
            $first = false;

            if ($this->timeoutMs > 0 && (microtime(true) - $startedAt) * 1000 >= $this->timeoutMs) {
                throw new GoBTCPayException("Payment poll timed out after {$this->timeoutMs}ms");
            }

            try {
                $payment = ($this->fetcher)($this->paymentId);
                $this->attempts++;

                $previousStatus = $this->lastStatus;
                $this->lastPayment = $payment;
                $this->lastStatus = $payment->status;

                $this->emit($this->changeListeners, $payment->status, $payment);
                $this->emit($this->updateListeners, $payment);

                if ($payment->status === PaymentStatus::Paid && $previousStatus !== PaymentStatus::Paid) {
                    $this->emit($this->paidListeners, $payment);
                }

                if (isset($this->until[$payment->status->value])) {
                    $this->emit($this->settledListeners, $payment);

                    return $payment;
                }
            } catch (\Throwable $err) {
                $this->attempts++;
                $this->emit($this->errorListeners, $err);
                if ($this->stopOnError) {
                    throw $err instanceof GoBTCPayException
                        ? $err
                        : new GoBTCPayException($err->getMessage(), 0, $err);
                }
            }
        }
    }

    /**
     * Alias of {@see poll}. Provided for parity with the async client SDKs; PHP
     * has no event loop, so this is also blocking.
     */
    public function pollAsync(): Payment
    {
        return $this->poll();
    }

    /**
     * Sleep for the given number of milliseconds. Overridable so tests can
     * replace real waiting with a no-op.
     */
    protected function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /**
     * @param list<callable> $listeners
     */
    private function emit(array $listeners, mixed ...$args): void
    {
        foreach ($listeners as $listener) {
            $listener(...$args);
        }
    }
}
