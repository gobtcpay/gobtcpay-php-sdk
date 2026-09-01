<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Dto;

/**
 * A configured webhook endpoint of the merchant.
 *
 * The signing secret is deliberately absent: it is returned only when the
 * endpoint is created or its secret is rotated, and never by a listing.
 */
final class WebhookEndpoint
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    /**
     * @param list<string> $events Subscribed event types, e.g. {@see WebhookEvent::TYPE_PAYMENT_STATUS_UPDATED}.
     * @param string $status One of the `STATUS_*` constants. A string rather than
     *                       an enum, so an unknown future status is carried
     *                       through instead of throwing on parse.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly WebhookScope $scope,
        public readonly array $events,
        public readonly string $status,
        public readonly ?string $label,
        public readonly string $merchantId = '',
        public readonly ?int $createdAt = null,
        public readonly ?int $updatedAt = null,
    ) {
    }

    /**
     * Build a WebhookEndpoint from one decoded `items[]` entry.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $scope */
        $scope = is_array($data['scope'] ?? null) ? $data['scope'] : [];

        $events = [];
        foreach ((array) ($data['events'] ?? []) as $event) {
            $events[] = (string) $event;
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            scope: WebhookScope::fromArray($scope),
            events: $events,
            status: (string) ($data['status'] ?? ''),
            label: isset($data['label']) && $data['label'] !== null ? (string) $data['label'] : null,
            merchantId: (string) ($data['merchantId'] ?? ''),
            createdAt: isset($data['createdAt']) && $data['createdAt'] !== null ? (int) $data['createdAt'] : null,
            updatedAt: isset($data['updatedAt']) && $data['updatedAt'] !== null ? (int) $data['updatedAt'] : null,
        );
    }

    /** Whether the endpoint is currently receiving deliveries. */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Whether the endpoint is subscribed to the given event type. */
    public function subscribesTo(string $eventType): bool
    {
        return in_array($eventType, $this->events, true);
    }
}
