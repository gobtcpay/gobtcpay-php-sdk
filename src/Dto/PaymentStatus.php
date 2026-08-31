<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Dto;

/**
 * Lifecycle status of a payment.
 *
 * - `Initiated` — waiting for funds.
 * - `Detected` — a transaction is visible but not yet deep enough to trust. It
 *   can still fall back to `Initiated` if the transaction is replaced (RBF) or
 *   drops out of the mempool. Do NOT release goods on this.
 * - `Paid` — funds confirmed on-chain. Terminal success state for payments made
 *   from an external wallet.
 * - `Expired` — the payment window closed. NOT terminal server-side: funds
 *   arriving within the grace period still move it to `Paid`.
 * - `Canceled` — canceled by the merchant. Terminal; late funds do not revive it.
 * - `Failed` — reserved, not produced by the external payment flow.
 * - `Cleared` — legacy in-app flow only; never set for external payments.
 */
enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Detected = 'detected';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case Cleared = 'cleared';

    /**
     * Statuses at which polling stops by default.
     *
     * @return list<self>
     */
    public static function finalStatuses(): array
    {
        return [self::Paid, self::Cleared, self::Expired, self::Canceled, self::Failed];
    }

    /** Whether this status is one of the default final (terminal) statuses. */
    public function isFinal(): bool
    {
        return in_array($this, self::finalStatuses(), true);
    }
}
