<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Dto;

use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;

/**
 * Webhook delivery envelope.
 *
 * `data` holds the same shape as `getPayment()` and is exposed both as a raw
 * decoded array ({@see $data}) and as a typed {@see Payment} via {@see payment}.
 *
 * **Not every delivery carries a payment.** A test delivery — the one the
 * platform sends when you call `testWebhook()` — arrives correctly signed, with
 * the same envelope, and with `data: null`. Check {@see hasPaymentData} before
 * calling {@see payment}.
 */
final class WebhookEvent
{
    /** The only event type emitted by GoBTC Pay today. */
    public const TYPE_PAYMENT_STATUS_UPDATED = 'payment.status.updated';

    /**
     * @param array<string, mixed> $data The decoded `data` object of the delivery.
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly int $createdAt,
        public readonly array $data,
        /** Whether this is a test delivery, sent on request rather than by a payment. */
        public readonly bool $test = false,
    ) {
    }

    /**
     * Build a WebhookEvent from a decoded delivery body.
     *
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        /** @var array<string, mixed> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        return new self(
            eventId: (string) ($body['eventId'] ?? ''),
            type: (string) ($body['type'] ?? ''),
            createdAt: (int) ($body['createdAt'] ?? 0),
            data: $data,
            test: ($body['test'] ?? false) === true,
        );
    }

    /**
     * Whether this delivery carries a payment at all.
     *
     * False for a test delivery, which is signed and well-formed but has no
     * payment behind it. Treat such a delivery as a successful receipt — it is
     * the whole point of the test — and do not look for an order to update.
     */
    public function hasPaymentData(): bool
    {
        return $this->data !== [];
    }

    /**
     * The event payload as a typed {@see Payment}.
     *
     * @throws GoBTCPayException If the delivery carries no payment — a test
     *                           delivery, most often. Guard with
     *                           {@see hasPaymentData}.
     */
    public function payment(): Payment
    {
        if (!$this->hasPaymentData()) {
            throw new GoBTCPayException(
                $this->test
                    ? 'This is a test delivery and carries no payment data'
                    : 'This delivery carries no payment data',
            );
        }

        return Payment::fromArray($this->data);
    }
}
