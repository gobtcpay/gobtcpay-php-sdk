<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Dto;

/**
 * A webhook endpoint exactly as it was just created — the same fields as
 * {@see WebhookEndpoint}, plus the signing secret.
 *
 * **The secret exists only here.** The platform returns it when an endpoint is
 * created or its secret is rotated, and never again: neither a listing nor a
 * lookup contains it, so it cannot be read back later. Store it as soon as you
 * receive it. **Rotation is the only way to get a new secret after creation,
 * and it invalidates the previous one at once.**
 *
 * This is a separate class rather than a subclass because {@see WebhookEndpoint}
 * is `final`, and it is flat on purpose so the field set matches the TypeScript
 * SDK's creation result. Use {@see toEndpoint()} where a plain
 * {@see WebhookEndpoint} is expected.
 */
final class WebhookEndpointWithSecret
{
    /**
     * @param list<string> $events Subscribed event types, e.g. {@see WebhookEvent::TYPE_PAYMENT_STATUS_UPDATED}.
     * @param string $status One of the {@see WebhookEndpoint} `STATUS_*` constants. A string
     *                       rather than an enum, so an unknown future status is carried
     *                       through instead of throwing on parse.
     * @param string $signingSecret Secret that signs deliveries to this endpoint. Returned once.
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
        public readonly string $signingSecret = '',
    ) {
    }

    /**
     * Build from the decoded `webhook` object of a create response.
     *
     * Parsing of the shared fields is delegated to {@see WebhookEndpoint::fromArray()},
     * so the two classes cannot drift apart on how a field is read.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $base = WebhookEndpoint::fromArray($data);

        return new self(
            id: $base->id,
            url: $base->url,
            scope: $base->scope,
            events: $base->events,
            status: $base->status,
            label: $base->label,
            merchantId: $base->merchantId,
            createdAt: $base->createdAt,
            updatedAt: $base->updatedAt,
            signingSecret: (string) ($data['signingSecret'] ?? ''),
        );
    }

    /** The same endpoint without its secret, as a listing would return it. */
    public function toEndpoint(): WebhookEndpoint
    {
        return new WebhookEndpoint(
            id: $this->id,
            url: $this->url,
            scope: $this->scope,
            events: $this->events,
            status: $this->status,
            label: $this->label,
            merchantId: $this->merchantId,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }
}
