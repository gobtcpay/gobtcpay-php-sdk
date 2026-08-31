# Examples

Runnable snippets for the GoBTC Pay PHP SDK.

## Setup

```bash
composer install
```

Copy `.env.example` to `.env` (or export the variables in your shell) and fill
in **test-contour** credentials — never a production key.

## `create-payment.php`

Creates a POS payment, prints the QR string, and polls until it settles.

```bash
POS_API_KEY=... POS_TERMINAL_ID=... php examples/create-payment.php
```

Optional: `POS_API_BASE_URL` to point at a non-default environment.

## `webhook-handler.php`

A plain-PHP webhook endpoint that verifies the `X-GoBTCPay-Signature` header,
de-duplicates on `eventId`, and dispatches `payment.status.updated` events.

Local smoke test with PHP's built-in server:

```bash
POS_WEBHOOK_SECRET=whsec_... php -S 127.0.0.1:8080 examples/webhook-handler.php
```

Then POST a signed delivery to `http://127.0.0.1:8080/`. In production, put this
behind your webserver and register the public URL in the merchant dashboard.

> Always feed the handler the **raw** request body. If you decode and re-encode
> the JSON first, the signature will not match.
