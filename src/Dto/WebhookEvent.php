<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Dto;

/**
 * Webhook delivery envelope.
 *
 * `data` holds the same shape as `getPayment()` and is exposed both as a raw
 * decoded array ({@see $data}) and as a typed {@see Payment} via {@see payment}.
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
        );
    }

    /** The event payload as a typed {@see Payment}. */
    public function payment(): Payment
    {
        return Payment::fromArray($this->data);
    }
}
