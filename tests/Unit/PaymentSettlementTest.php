<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Unit;

use GoBTCPay\PosApiSdk\Dto\Payment;
use GoBTCPay\PosApiSdk\Dto\PaymentStatus;
use GoBTCPay\PosApiSdk\Dto\PaymentTransaction;
use GoBTCPay\PosApiSdk\Tests\Support\Responses;
use PHPUnit\Framework\TestCase;

/**
 * `paidAt` and `transactions[]` — the two fields the API already returns and
 * the DTO used to drop on the floor.
 */
final class PaymentSettlementTest extends TestCase
{
    public function testPaidAtIsParsedAsUnixSeconds(): void
    {
        $payment = Payment::fromArray(
            Responses::samplePayment('paid') + ['paidAt' => 1_700_000_600],
        );

        self::assertSame(1_700_000_600, $payment->paidAt);
    }

    public function testPaidAtIsNullWhileThePaymentHasNotSettled(): void
    {
        $payment = Payment::fromArray(Responses::samplePayment('detected') + ['paidAt' => null]);

        self::assertNull($payment->paidAt);
    }

    /**
     * A server older than the field sends no `paidAt` at all. That has to read
     * as "not settled yet", not blow up — the SDK is published before the
     * backend it talks to is deployed.
     */
    public function testPaidAtIsNullWhenTheServerOmitsIt(): void
    {
        $payment = Payment::fromArray(Responses::samplePayment('paid'));

        self::assertNull($payment->paidAt);
        self::assertSame([], $payment->transactions);
    }

    public function testTransactionsAreParsedWithCanonicalStringSatoshis(): void
    {
        $payment = Payment::fromArray(
            Responses::samplePayment('paid') + [
                'transactions' => [Responses::onchainTransaction()],
            ],
        );

        self::assertCount(1, $payment->transactions);
        $tx = $payment->transactions[0];
        self::assertInstanceOf(PaymentTransaction::class, $tx);
        self::assertSame('a1b2c3', $tx->txid);
        // Sent as the string "15000"; an int is what a caller can do arithmetic on.
        self::assertSame(15000, $tx->amountSats);
        self::assertSame(870_000, $tx->blockHeight);
        self::assertSame(1_700_000_500, $tx->blockTime);
        self::assertSame(3, $tx->confirmations);
        self::assertSame(250, $tx->feeSats);
        self::assertTrue($tx->isConfirmed());
    }

    /**
     * In the mempool: no block, and `confirmations` is **zero**, not null. Null
     * is reserved for the third state below, and conflating the two makes a
     * confirmed receipt read as unconfirmed.
     */
    public function testMempoolTransactionHasZeroConfirmationsAndNoBlock(): void
    {
        $payment = Payment::fromArray(
            Responses::samplePayment('detected') + [
                'transactions' => [Responses::onchainTransaction(confirmed: false)],
            ],
        );

        $tx = $payment->transactions[0];
        self::assertNull($tx->blockHeight);
        self::assertNull($tx->blockTime);
        self::assertSame(0, $tx->confirmations);
        self::assertNull($tx->feeSats);
        self::assertFalse($tx->isConfirmed());
    }

    /**
     * In a block, but the explorer could not be reached: `confirmations` is
     * null — "unknown", which is not the same as none and must not be rendered
     * as zero. `isConfirmed()` answers from the block height, so it stays true.
     */
    public function testConfirmedTransactionWithUnknownChainTipKeepsNullConfirmations(): void
    {
        $tx = PaymentTransaction::fromArray(
            array_merge(Responses::onchainTransaction(), ['confirmations' => null]),
        );

        self::assertSame(870_000, $tx->blockHeight);
        self::assertNull($tx->confirmations);
        self::assertTrue($tx->isConfirmed());
    }

    /** A zero fee is a real value and must not collapse into null. */
    public function testZeroFeeIsKept(): void
    {
        $tx = PaymentTransaction::fromArray(
            Responses::onchainTransaction() + [],
        );
        self::assertSame(250, $tx->feeSats);

        $free = PaymentTransaction::fromArray(
            array_merge(Responses::onchainTransaction(), ['feeSats' => '0']),
        );
        self::assertSame(0, $free->feeSats);
    }

    /** A malformed entry is skipped rather than crashing the whole payment. */
    public function testNonArrayTransactionEntriesAreIgnored(): void
    {
        $payment = Payment::fromArray(
            Responses::samplePayment('paid') + [
                'transactions' => ['nonsense', Responses::onchainTransaction()],
            ],
        );

        self::assertCount(1, $payment->transactions);
    }

    /**
     * The point of the two fields: `paid` alone does not prove settlement was
     * recorded, so a consumer deciding "the outcome is decided" reads `paidAt`.
     */
    public function testPaidStatusWithoutPaidAtIsNotEvidenceOfSettlement(): void
    {
        $payment = Payment::fromArray(Responses::samplePayment('paid'));

        self::assertSame(PaymentStatus::Paid, $payment->status);
        self::assertTrue($payment->status->isFinal());
        self::assertNull($payment->paidAt);
    }
}
