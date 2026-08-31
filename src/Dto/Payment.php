<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Dto;

/**
 * A created or fetched payment.
 *
 * Immutable value object built from an API `success` payload via {@see fromArray}.
 */
final class Payment
{
    /**
     * @param int|string|null $expiresAt Expiry, as unix seconds or ISO string, or null.
     * @param mixed $metadata Opaque merchant-supplied metadata.
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly PaymentStatus $status,
        public readonly string $merchantId,
        public readonly string $merchantName,
        public readonly string $storeId,
        public readonly string $posTerminalId,
        public readonly ?string $posTerminalLabel,
        public readonly ?string $posTerminalLocation,
        public readonly ?string $merchantDisplayName,
        public readonly float $amount,
        public readonly int $amountSats,
        public readonly string $currency,
        public readonly float $btcPriceInUsd,
        public readonly float $btcPriceInCurrency,
        public readonly string $qrString,
        /**
         * Monotonic revision, bumped on every status change. Webhook deliveries
         * are retried and therefore unordered: compare `version` against the last
         * one you stored and ignore anything not greater, or a stale `detected`
         * will overwrite a fresh `paid`.
         */
        public readonly int $version,
        public readonly ?string $externalId = null,
        public readonly ?string $description = null,
        public readonly ?string $currencySymbol = null,
        public readonly ?string $btcAddress = null,
        public readonly int|string|null $expiresAt = null,
        public readonly mixed $metadata = null,
        /** Absolute link to the hosted checkout page for this payment. */
        public readonly ?string $checkoutUrl = null,
    ) {
    }

    /**
     * Build a Payment from a decoded API `success` payload.
     *
     * Unknown keys are ignored; missing optional keys fall back to null.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            paymentId: (string) ($data['paymentId'] ?? ''),
            status: PaymentStatus::from((string) ($data['status'] ?? '')),
            merchantId: (string) ($data['merchantId'] ?? ''),
            merchantName: (string) ($data['merchantName'] ?? ''),
            storeId: (string) ($data['storeId'] ?? ''),
            posTerminalId: (string) ($data['posTerminalId'] ?? ''),
            posTerminalLabel: self::nullableString($data['posTerminalLabel'] ?? null),
            posTerminalLocation: self::nullableString($data['posTerminalLocation'] ?? null),
            merchantDisplayName: self::nullableString($data['merchantDisplayName'] ?? null),
            amount: (float) ($data['amount'] ?? 0),
            amountSats: (int) ($data['amountSats'] ?? 0),
            currency: (string) ($data['currency'] ?? ''),
            btcPriceInUsd: (float) ($data['btcPriceInUsd'] ?? 0),
            btcPriceInCurrency: (float) ($data['btcPriceInCurrency'] ?? 0),
            qrString: (string) ($data['qrString'] ?? ''),
            version: (int) ($data['version'] ?? 0),
            externalId: self::nullableString($data['externalId'] ?? null),
            description: self::nullableString($data['description'] ?? null),
            currencySymbol: self::nullableString($data['currencySymbol'] ?? null),
            btcAddress: self::nullableString($data['btcAddress'] ?? null),
            expiresAt: self::nullableIntString($data['expiresAt'] ?? null),
            metadata: $data['metadata'] ?? null,
            checkoutUrl: self::nullableString($data['checkoutUrl'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function nullableIntString(mixed $value): int|string|null
    {
        if ($value === null) {
            return null;
        }

        return is_int($value) ? $value : (string) $value;
    }
}
