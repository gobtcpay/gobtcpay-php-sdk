<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Dto;

/**
 * What a webhook endpoint is subscribed to: the whole merchant, one store, or
 * one POS terminal.
 *
 * Only deliveries matching the scope reach the endpoint, so a shop that
 * registered a store-scoped endpoint receives nothing for payments created on
 * another store.
 */
final class WebhookScope
{
    public const TYPE_MERCHANT = 'merchant';
    public const TYPE_STORE = 'store';
    public const TYPE_POS = 'pos';

    /**
     * @param string $type One of the `TYPE_*` constants. Kept as a string rather
     *                     than an enum so an unknown future scope is carried
     *                     through instead of throwing on parse.
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $storeId = null,
        public readonly ?string $posTerminalId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? self::TYPE_MERCHANT),
            storeId: isset($data['storeId']) ? (string) $data['storeId'] : null,
            posTerminalId: isset($data['posTerminalId']) ? (string) $data['posTerminalId'] : null,
        );
    }

    /** Whether this endpoint receives deliveries for every store of the merchant. */
    public function isMerchantWide(): bool
    {
        return $this->type === self::TYPE_MERCHANT;
    }
}
