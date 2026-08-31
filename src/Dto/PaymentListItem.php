<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Dto;

/** One row of `payments.list`. */
final class PaymentListItem
{
    /**
     * @param mixed $metadata Opaque merchant-supplied metadata.
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly PaymentStatus $status,
        public readonly float $amount,
        public readonly int $amountSats,
        public readonly string $currency,
        public readonly ?string $description,
        public readonly ?string $externalId,
        public readonly string $btcAddress,
        public readonly float $btcPriceInUsd,
        public readonly float $btcPriceInCurrency,
        public readonly string $network,
        public readonly int $createdAt,
        public readonly ?int $expiresAt,
        public readonly string $storeId,
        public readonly string $posTerminalId,
        public readonly ?string $posTerminalLabel,
        public readonly ?string $posTerminalLocation,
        public readonly ?string $currencySymbol = null,
        public readonly mixed $metadata = null,
    ) {
    }

    /**
     * Build a PaymentListItem from one decoded `items[]` entry.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            paymentId: (string) ($data['paymentId'] ?? ''),
            status: PaymentStatus::from((string) ($data['status'] ?? '')),
            amount: (float) ($data['amount'] ?? 0),
            amountSats: (int) ($data['amountSats'] ?? 0),
            currency: (string) ($data['currency'] ?? ''),
            description: self::nullableString($data['description'] ?? null),
            externalId: self::nullableString($data['externalId'] ?? null),
            btcAddress: (string) ($data['btcAddress'] ?? ''),
            btcPriceInUsd: (float) ($data['btcPriceInUsd'] ?? 0),
            btcPriceInCurrency: (float) ($data['btcPriceInCurrency'] ?? 0),
            network: (string) ($data['network'] ?? ''),
            createdAt: (int) ($data['createdAt'] ?? 0),
            expiresAt: isset($data['expiresAt']) && $data['expiresAt'] !== null ? (int) $data['expiresAt'] : null,
            storeId: (string) ($data['storeId'] ?? ''),
            posTerminalId: (string) ($data['posTerminalId'] ?? ''),
            posTerminalLabel: self::nullableString($data['posTerminalLabel'] ?? null),
            posTerminalLocation: self::nullableString($data['posTerminalLocation'] ?? null),
            currencySymbol: self::nullableString($data['currencySymbol'] ?? null),
            metadata: $data['metadata'] ?? null,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
