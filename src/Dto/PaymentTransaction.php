<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Dto;

/**
 * One on-chain receipt observed for a payment.
 *
 * Independent of how the payment was made: a payment settled from inside the
 * GoMining wallet reports its transaction here exactly like one paid from an
 * external wallet.
 *
 * Three states, and `confirmations` is the field that distinguishes them:
 *
 * - **in the mempool** — `blockHeight` and `blockTime` null, `confirmations` `0`;
 * - **in a block** — all three set, `confirmations` counted against the current
 *   chain tip, so it keeps growing between reads;
 * - **in a block, tip unknown** — `blockHeight` set, `confirmations` **null**
 *   because the explorer could not be reached. That is "we do not know", a
 *   different statement from "no confirmations", and it must not be rendered as
 *   zero.
 *
 * One documented exception to the second state: rows confirmed before receipts
 * shipped carry a height with no block time, and there is no backfill.
 */
final class PaymentTransaction
{
    /**
     * @param int $amountSats Received amount, always positive.
     * @param int|null $confirmations `0` in the mempool, null when the chain tip is unknown.
     * @param int|null $feeSats Fee paid by that transaction; may legitimately be 0.
     */
    public function __construct(
        public readonly string $txid,
        public readonly int $amountSats,
        public readonly ?int $blockHeight,
        public readonly ?int $blockTime,
        public readonly ?int $confirmations,
        public readonly ?int $feeSats,
    ) {
    }

    /**
     * Build a PaymentTransaction from one decoded `transactions[]` entry.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            txid: (string) ($data['txid'] ?? ''),
            // Satoshi amounts travel as canonical decimal strings so that a
            // JSON number never rounds them. PHP integers are 64-bit, so the
            // cast back is exact.
            amountSats: (int) ($data['amountSats'] ?? 0),
            blockHeight: self::nullableInt($data['blockHeight'] ?? null),
            blockTime: self::nullableInt($data['blockTime'] ?? null),
            confirmations: self::nullableInt($data['confirmations'] ?? null),
            feeSats: self::nullableInt($data['feeSats'] ?? null),
        );
    }

    /** Whether this receipt is already in a block. */
    public function isConfirmed(): bool
    {
        return $this->blockHeight !== null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
