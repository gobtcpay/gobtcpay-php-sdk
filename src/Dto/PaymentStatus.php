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
     * The set at which {@see \GoBTCPay\PosApiSdk\PaymentPoller} gives up by
     * default — "no longer worth polling", which is NOT the same as "the
     * outcome is decided".
     *
     * Two members of this set are not terminal on the server:
     *
     * - `Expired` never is. The payment window closing does not close the
     *   payment: funds arriving within the grace period still move it to
     *   `Paid` hours later. Cancelling an order here is irreversible and the
     *   money may still arrive.
     * - `Paid` is terminal as a status, but on a server older than the
     *   settlement-notification fix it can be reached before settlement is
     *   recorded: `paidAt` was filled in afterwards without bumping `version`
     *   and without emitting an event, so a consumer that stopped polling here
     *   never learned the payment had settled.
     *
     * Use this set when you mean "stop spending requests". When you mean "the
     * outcome is decided", decide it yourself from the status and
     * {@see Payment::$paidAt} rather than from this list.
     *
     * @return list<self>
     */
    public static function finalStatuses(): array
    {
        return [self::Paid, self::Cleared, self::Expired, self::Canceled, self::Failed];
    }

    /**
     * Whether this status is in {@see finalStatuses} — the polling stop set.
     *
     * Read its documentation before branching on this: `true` here does not
     * mean the payment can no longer change.
     */
    public function isFinal(): bool
    {
        return in_array($this, self::finalStatuses(), true);
    }
}
