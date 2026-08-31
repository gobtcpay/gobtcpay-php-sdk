<?php

/**
 * Plain-PHP webhook endpoint for GoBTC Pay `payment.status.updated` events.
 *
 * Point your webserver at this file (e.g. https://shop.example.com/webhooks/gobtcpay)
 * and register that URL + copy the signing secret from the merchant dashboard.
 *
 * Usage (local smoke test with PHP's built-in server):
 *   POS_WEBHOOK_SECRET=whsec_... php -S 127.0.0.1:8080 examples/webhook-handler.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GoBTCPay\PosApiSdk\Dto\WebhookEvent;
use GoBTCPay\PosApiSdk\Exception\WebhookSignatureException;
use GoBTCPay\PosApiSdk\WebhookHandler;

$secret = getenv('POS_WEBHOOK_SECRET') ?: '';
if ($secret === '') {
    http_response_code(500);
    echo 'POS_WEBHOOK_SECRET is not set';

    return;
}

$webhooks = new WebhookHandler($secret);

// Register listeners once; they run for every non-duplicate delivery.
$webhooks->on(WebhookEvent::TYPE_PAYMENT_STATUS_UPDATED, function (WebhookEvent $event): void {
    $payment = $event->payment();
    // Compare $payment->version against the last one you stored before applying
    // this update — webhook deliveries are retried and therefore unordered.
    error_log(sprintf(
        'payment %s -> %s (v%d)',
        $payment->paymentId,
        $payment->status->value,
        $payment->version,
    ));
});

// Read the RAW body — do NOT json_decode and re-encode it, or the signature
// will not match.
$rawBody = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_GOBTCPAY_SIGNATURE'] ?? null;

try {
    $event = $webhooks->handle($rawBody, $signature);
    // Any 2xx acknowledges the delivery; a non-2xx makes GoBTC Pay retry it.
    http_response_code(200);
    echo $event === null ? 'duplicate ignored' : 'ok';
} catch (WebhookSignatureException $e) {
    http_response_code(400);
    echo 'invalid signature';
}
