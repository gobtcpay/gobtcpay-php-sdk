<?php

/**
 * Create a POS payment and print the QR string to present to the payer, then
 * poll until it settles.
 *
 * Usage:
 *   composer install
 *   POS_API_KEY=... POS_TERMINAL_ID=... php examples/create-payment.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GoBTCPay\PosApiSdk\Dto\PaymentStatus;
use GoBTCPay\PosApiSdk\Exception\GoBTCPayException;
use GoBTCPay\PosApiSdk\GoBTCPay;

$apiKey = getenv('POS_API_KEY') ?: '';
$terminalId = getenv('POS_TERMINAL_ID') ?: '';
$baseUrl = getenv('POS_API_BASE_URL') ?: null;

if ($apiKey === '' || $terminalId === '') {
    fwrite(STDERR, "Set POS_API_KEY and POS_TERMINAL_ID in the environment.\n");
    exit(1);
}

// Initialize once. The client signs every request for you with a fresh HMAC
// signature + timestamp — there is no token to manage or refresh.
$btcPay = new GoBTCPay(
    apiKey: $apiKey,
    posTerminalId: $terminalId,
    baseUrl: $baseUrl,
);

try {
    $payment = $btcPay->createPayment(
        amount: 10,
        currency: 'USD',
        description: 'Order #1024',
    );

    echo "Payment created\n";
    echo "  id:     {$payment->paymentId}\n";
    echo "  status: {$payment->status->value}\n";
    echo "  qr:     {$payment->qrString}\n";
    if ($payment->checkoutUrl !== null) {
        echo "  url:    {$payment->checkoutUrl}\n";
    }

    echo "\nWatching for the outcome (Ctrl-C to stop)…\n";
    $poller = $btcPay->watchPayment(['paymentId' => $payment->paymentId, 'timeoutMs' => 15 * 60 * 1000]);
    $poller->onChange(function (PaymentStatus|string $status): void {
        $value = $status instanceof PaymentStatus ? $status->value : $status;
        echo "  status: {$value}\n";
    });

    $final = $poller->poll();
    echo "\nFinal status: {$final->status->value}\n";
} catch (GoBTCPayException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
