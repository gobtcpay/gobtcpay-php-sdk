# gobtcpay/php-merchant-sdk

[![CI](https://github.com/gobtcpay/gobtcpay-php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/gobtcpay/gobtcpay-php-sdk/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/gobtcpay/php-merchant-sdk)](https://packagist.org/packages/gobtcpay/php-merchant-sdk)
[![License](https://img.shields.io/packagist/l/gobtcpay/php-merchant-sdk)](./LICENSE)

PHP client for the **GoBTC Pay API** — accept Bitcoin payments and track them
until they settle on-chain. Two clients share one toolkit:

- **`GoBTCPay`** — POS terminals. Every request is signed with a fresh
  HMAC-SHA256 signature + timestamp (per-terminal key).
- **`GoBTCPayServer`** — server-side shop integrations. Authenticates with the
  merchant's secret key (`sk_live_…`): create a payment for an order, track it,
  cancel it, reconcile.

Both share webhook verification and payment polling helpers.

## Requirements

- PHP **8.1+**
- A [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client and
  [PSR-17](https://www.php-fig.org/psr/psr-17/) factories. Install
  [Guzzle](https://docs.guzzlephp.org/) and the SDK auto-discovers it — or pass
  your own implementation.

## Installation

```bash
composer require gobtcpay/php-merchant-sdk guzzlehttp/guzzle
```

`guzzlehttp/guzzle` is a suggestion, not a hard dependency: any PSR-18 client
works. With Guzzle installed you don't need to wire anything up.

> ⚠️ The POS `apiKey` and the server `sk_live_…` key are **secrets**. Keep them
> server-side or on a controlled POS device. Never ship them to a browser.

## Quick start

### POS terminal (`GoBTCPay`)

```php
use GoBTCPay\PosApiSdk\GoBTCPay;

$btcPay = new GoBTCPay(
    apiKey: getenv('POS_API_KEY'),
    posTerminalId: getenv('POS_TERMINAL_ID'),
);

// Create a payment and show the QR to the customer.
$payment = $btcPay->createPayment(
    amount: 10,
    currency: 'USD',
    description: 'Order #1024',
);
echo $payment->paymentId, PHP_EOL;
echo $payment->qrString, PHP_EOL;

// Fetch the current state.
$latest = $btcPay->getPayment($payment->paymentId);
echo $latest->status->value; // initiated | detected | paid | expired | canceled | failed | cleared
```

### Server-side shop (`GoBTCPayServer`)

```php
use GoBTCPay\PosApiSdk\GoBTCPayServer;

$gobtcpay = new GoBTCPayServer(apiKey: getenv('GOBTCPAY_SECRET_KEY'));

$payment = $gobtcpay->createPayment(
    amount: 49.99,
    currency: 'USD',
    externalId: "order-{$order->id}", // makes create idempotent — pass it!
);

header('Location: ' . $payment->checkoutUrl);
```

### Bring your own HTTP client

Any PSR-18 client + PSR-17 factories work. Pass them explicitly to skip
auto-discovery (or to reuse a configured client):

```php
use GoBTCPay\PosApiSdk\GoBTCPayServer;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory();
$gobtcpay = new GoBTCPayServer(
    apiKey: getenv('GOBTCPAY_SECRET_KEY'),
    httpClient: new Client(['timeout' => 30]),
    requestFactory: $factory,
    streamFactory: $factory,
);
```

## Configuration

### `GoBTCPay` (POS)

| Option           | Required | Default                                       | Description                                     |
| ---------------- | -------- | --------------------------------------------- | ----------------------------------------------- |
| `apiKey`         | yes      | —                                             | Per-terminal secret used to sign every request. |
| `posTerminalId`  | no       | —                                             | Default terminal for `createPayment`.           |
| `baseUrl`        | no       | `https://api.gobtcpay.com/public/api/v1.1`    | Override for staging/dev environments.          |
| `timeoutMs`      | no       | `30000`                                       | Per-request timeout.                            |
| `httpClient`     | no       | auto-discovered Guzzle                        | Your PSR-18 client.                             |
| `requestFactory` | no       | auto-discovered Guzzle/Nyholm                 | Your PSR-17 request factory.                    |
| `streamFactory`  | no       | auto-discovered Guzzle/Nyholm                 | Your PSR-17 stream factory.                     |

### `GoBTCPayServer`

| Option           | Required | Default                                       | Description                                             |
| ---------------- | -------- | --------------------------------------------- | ------------------------------------------------------- |
| `apiKey`         | yes      | —                                             | Merchant secret key (`sk_live_…`). `pk_live_…` rejected.|
| `baseUrl`        | no       | `https://api.gobtcpay.com/public/api/v1.2`    | Override for staging/dev environments.                  |
| `timeoutMs`      | no       | `30000`                                       | Per-request timeout.                                    |
| `maxRetries`     | no       | `2`                                           | Retries after the first attempt (network / 429 / 5xx).  |
| `onEvent`        | no       | —                                             | `callable(array $event)` called once per attempt.       |
| `httpClient` / `requestFactory` / `streamFactory` | no | auto-discovered | Same as above.                       |

## Methods

### `GoBTCPay`

```php
$btcPay->createPayment(amount, currency, posTerminalId?, description?, ttlSeconds?, externalId?): Payment;
$btcPay->getPayment(paymentId): Payment;
$btcPay->watchPayment(['paymentId' => ..., 'intervalMs' => ..., 'timeoutMs' => ..., 'until' => [...], 'immediate' => ..., 'stopOnError' => ...]): PaymentPoller;
$btcPay->webhooks(signingSecret, toleranceSeconds?, dedupeCacheSize?): WebhookHandler;
```

### `GoBTCPayServer`

```php
$gobtcpay->createPayment(amount, currency, externalId?, description?, ttlSeconds?): Payment;
$gobtcpay->getPayment(paymentId): Payment;
$gobtcpay->cancelPayment(paymentId): Payment;
$gobtcpay->listPayments(status?, externalId?, dateRange?, limit?): Generator<PaymentListItem>;
$gobtcpay->listPaymentsPage(status?, externalId?, dateRange?, limit?, skip?): array{items: PaymentListItem[], totalCount: int};
$gobtcpay->watchPayment([...]): PaymentPoller;
$gobtcpay->listWebhooks(status?, limit?, skip?): array{items: WebhookEndpoint[], totalCount: int};
$gobtcpay->testWebhook(webhookId): void;
$gobtcpay->webhooks(signingSecret, toleranceSeconds?, dedupeCacheSize?): WebhookHandler;
```

`listPayments()` is a generator that pages transparently, newest first:

```php
use GoBTCPay\PosApiSdk\Dto\PaymentStatus;

foreach ($gobtcpay->listPayments(status: [PaymentStatus::Paid]) as $item) {
    $this->reconcile($item);
}
```

## Auto-polling

`watchPayment()` returns a `PaymentPoller`. Because PHP request handlers are
synchronous, `poll()` is a **blocking** loop: it calls `getPayment` on an
interval until the payment reaches one of the statuses it stops at (`paid` /
`cleared` / `expired` / `canceled` / `failed`). The interval defaults to and is
clamped to a minimum of **3 seconds**.

```php
$poller = $btcPay->watchPayment(['paymentId' => $payment->paymentId, 'intervalMs' => 3000]);

$poller->onChange(fn ($status) => render($status));   // any status change
$poller->onUpdate(fn ($payment) => {});               // every successful poll
$poller->onPaid(fn ($payment) => {});                 // transition into `paid`
$poller->onSettled(fn ($payment) => {});              // the poller stopped (see the note below)
$poller->onError(fn ($error) => {});                  // a poll failed

$final = $poller->poll(); // blocks, returns the settled Payment
```

Options (array keys): `paymentId` (required), `intervalMs`, `timeoutMs`,
`immediate` (default `true`), `until` (list of `PaymentStatus`), `stopOnError`.

> **`paid` vs `cleared`:** `paid` means the funds are confirmed on-chain — the
> success state for external wallet payments. To stop as soon as that
> happens, pass `'until' => [PaymentStatus::Paid]`, or react to `onPaid` while
> polling continues.

> **Stopping is not the same as deciding.** The default stop set is "no longer
> worth polling", not "nothing can change". `expired` in particular is **not
> terminal on the server**: the window closing does not close the payment, and
> funds arriving within the grace period still move it to `paid` afterwards — so
> cancelling an order on `expired` can strand a payment that later succeeds.
> When your decision is irreversible, read `$payment->paidAt` (settlement time,
> `null` until the payment settles) and `$payment->transactions` rather than
> `PaymentStatus::isFinal()`.

## Webhooks

Register a webhook URL in the merchant dashboard to receive
`payment.status.updated` events. The handler verifies the `X-GoBTCPay-Signature`
header (`t={timestamp},v1={hmac_hex}`), enforces a replay window, and
de-duplicates on `eventId`.

```php
$webhooks = $gobtcpay->webhooks(getenv('POS_WEBHOOK_SECRET'));

$webhooks->on('payment.status.updated', function ($event) {
    if (!$event->hasPaymentData()) {
        return;                              // a test delivery — nothing to update
    }

    $payment = $event->payment();            // typed Payment
    // $event->data is also the raw decoded array
    error_log("{$payment->paymentId} -> {$payment->status->value}");
});
```

**Guard on `hasPaymentData()`.** A test delivery (see below) arrives correctly
signed, through the same listener, with no payment behind it — `$event->test` is
`true` and `$event->data` is empty. Calling `payment()` on one throws a
`GoBTCPayException`; answering 2xx anyway is the right behaviour, and it is what
tells the sender the endpoint works.

`handle()` returns the parsed `WebhookEvent`, or `null` if it was a duplicate.
Use `constructEvent()` to only verify + parse without dispatching. **Always feed
the raw request body** — do not decode and re-encode it, or the signature will
not match.

### Inspecting and testing endpoints

`listWebhooks()` returns the endpoints configured for the merchant, and
`testWebhook()` asks the platform to send a test delivery to one of them:

```php
foreach ($gobtcpay->listWebhooks()['items'] as $endpoint) {
    echo $endpoint->url, ' ', $endpoint->status, PHP_EOL;
}

$gobtcpay->testWebhook($endpointId);
```

The test delivery is a normal signed delivery of type `payment.status.updated`
carrying `test: true` and no payment data, so your existing handler receives it —
guard with `hasPaymentData()` as shown above and answer 2xx.

Three things worth knowing before you build a "test my webhook" button on this:

- **`testWebhook()` returning normally means the delivery was queued, not that
  it arrived.** Deliveries are dispatched by a scheduled job, so the test lands
  at your endpoint some time later — and can still fail there. Whether the
  webhook works is answered at the receiving end, by observing the delivery.
- **Both calls are merchant-level**, so `listWebhooks()` doubles as the cheapest
  probe of whether a key can manage webhooks at all — an empty list is a
  perfectly successful answer, while a key restricted to a single store is
  refused with HTTP 403 (`AuthException`). **The refusal does not say why**: the
  same 403 covers a store-scoped key, a revoked key, an unknown key, a
  publishable key and an inactive merchant. Show the server's message rather
  than guessing the cause.
- **`listWebhooks()` returns one page.** Compare the page against `totalCount`
  and page with `skip` before concluding that a URL is not registered.

### Plain PHP

```php
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_GOBTCPAY_SIGNATURE'] ?? null;

try {
    $webhooks->handle($rawBody, $signature);
    http_response_code(200); // any 2xx acknowledges; non-2xx is retried
} catch (\GoBTCPay\PosApiSdk\Exception\WebhookSignatureException) {
    http_response_code(400);
}
```

### Laravel

```php
use Illuminate\Http\Request;

Route::post('/webhooks/gobtcpay', function (Request $request) use ($webhooks) {
    try {
        $webhooks->handle(
            $request->getContent(), // RAW body
            $request->header('X-GoBTCPay-Signature'),
        );
        return response()->noContent(); // 204
    } catch (\GoBTCPay\PosApiSdk\Exception\WebhookSignatureException) {
        return response('invalid signature', 400);
    }
});
```

### Symfony

```php
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Route('/webhooks/gobtcpay', methods: ['POST'])]
public function gobtcpay(Request $request): Response
{
    try {
        $this->webhooks->handle(
            $request->getContent(), // RAW body
            $request->headers->get('X-GoBTCPay-Signature'),
        );
        return new Response('', Response::HTTP_NO_CONTENT);
    } catch (\GoBTCPay\PosApiSdk\Exception\WebhookSignatureException) {
        return new Response('invalid signature', Response::HTTP_BAD_REQUEST);
    }
}
```

## Error handling

Every error extends `GoBTCPay\PosApiSdk\Exception\GoBTCPayException`:

| Exception                   | When                                                     |
| --------------------------- | -------------------------------------------------------- |
| `ApiException`              | Any API error envelope / non-2xx (`->httpStatus`, `->body`, `->requestId`, `->type()`). Base for the ones below. |
| `AuthException`             | 401 / 403 — key missing, malformed, revoked, not permitted. |
| `ValidationException`       | 400 / 402 — request rejected; retrying unchanged won't help. |
| `NotFoundException`         | 404 — no such payment or webhook endpoint.               |
| `RateLimitException`        | 429 — too many requests (`->retryAfterMs`).              |
| `ServerException`           | 5xx — the API failed; safe to retry idempotent calls.    |
| `NetworkException`          | No response: connection / DNS / TLS / timeout (`->isTimeout`). |
| `WebhookSignatureException` | A webhook signature failed verification.                 |

```php
use GoBTCPay\PosApiSdk\Exception\ApiException;
use GoBTCPay\PosApiSdk\Exception\NetworkException;
use GoBTCPay\PosApiSdk\Exception\RateLimitException;

try {
    $payment = $gobtcpay->createPayment(amount: 10, currency: 'USD', externalId: 'order-1');
} catch (RateLimitException $e) {
    // back off for $e->retryAfterMs
} catch (ApiException $e) {
    error_log("API {$e->httpStatus} ({$e->requestId}): {$e->getMessage()}");
} catch (NetworkException $e) {
    // connection failure / timeout ($e->isTimeout)
}
```

The server client retries network failures, `429` and `5xx` automatically
(`maxRetries`, default 2) with exponential backoff + jitter. Reads and
idempotent `create` calls (those with an `externalId`) are retried; a
`create` without an `externalId` is not, so a retry can't double-charge. The
POS client does not retry — a human at the terminal sees the failure and taps
again.

## Development

```bash
composer install
composer lint       # php-cs-fixer --dry-run --diff
composer lint:fix   # apply fixes
composer phpstan    # static analysis (level 8)
composer test       # PHPUnit unit suite
```

### Docker

A `Dockerfile` is included so that all checks run in an identical environment
locally and in CI:

```bash
docker build -t gobtcpay-php-sdk .
docker run --rm gobtcpay-php-sdk sh -c 'composer install && composer test && composer phpstan && composer lint'
```

CI builds this image per pipeline and runs `lint`, `phpstan`, and `test` jobs
against it.

### Testing

**Unit tests** — pure logic, no network. They gate every MR and run in CI:
request signing (HMAC cross-checked against `hash_hmac`), the envelope
transport (retries, typed exceptions), webhook verification (signature / replay
window / de-dup), and the poller lifecycle.

```bash
composer test
composer test:coverage
```

**Integration tests** — exercise the SDK end-to-end against a live POS API on
the **test contour**. Opt-in and self-skipping unless credentials are present:

```bash
cp .env.example .env   # fill in test-contour values, then:
POS_API_KEY=... POS_TERMINAL_ID=... composer test:integration
```

Use **test-contour** credentials only — never a production key.

## Versioning

The **API version is pinned inside each client** (POS `v1.1`, server `v1.2`),
exported as `GoBTCPay::API_VERSION` / `GoBTCPayServer::SERVER_API_VERSION` — you
don't put it in a URL. Need a different version or environment? Override
`baseUrl`.

## Publishing

### How it works

1. A merge into `main` triggers the `lint`, `phpstan`, and `test` GitHub
   Actions jobs.
2. If they pass, the `release` job runs
   [semantic-release](https://semantic-release.gitbook.io/), which reads the
   [Conventional Commits](https://www.conventionalcommits.org/) since the
   last release: `fix:` bumps a patch version, `feat:` bumps minor, a
   `BREAKING CHANGE:` footer bumps major. Anything else (`docs:`, `chore:`,
   `ci:`, …) does not trigger a release.
3. On a release, semantic-release updates `CHANGELOG.md`, bumps
   `SDK_VERSION` in `src/GoBTCPay.php` / `src/GoBTCPayServer.php`, commits
   `chore(release): x.y.z [skip ci]`, tags it, and creates a GitHub release.
4. That push fires a GitHub webhook (**Settings → Webhooks**, configured on
   this repo) that pings `https://packagist.org/api/github`. Packagist
   re-reads the tag from GitHub and publishes it as a new version — no
   manual "Update" click, no separate publish step.

Distribution is through the public [Packagist](https://packagist.org/)
package `gobtcpay/php-merchant-sdk`.

### Giving this to a merchant

Nothing special — it's a public Composer package like any other. Point them
at this repo or the [Packagist page](https://packagist.org/packages/gobtcpay/php-merchant-sdk);
they run `composer require gobtcpay/php-merchant-sdk` in their own project and
follow [Installation](#installation) / [Quick start](#quick-start). No token,
no invite, no access request. Their `apiKey` / `sk_live_…` credentials are
issued separately and are unrelated to installing the package.

**Exception — WordPress/WooCommerce/Shopify plugins:** those merchants
typically don't run Composer at all, and bundling this SDK's dependencies
(Guzzle, PSR interfaces) as-is risks class name collisions with other
plugins on the same site. A plugin that embeds this SDK should vendor it at
build time with a namespace-prefixing tool
([php-scoper](https://github.com/humbug/php-scoper) or
[Strauss](https://github.com/BrianHenryIE/strauss)) and ship the prefixed
code inside the plugin's own zip — the merchant never touches Composer or
this package directly.

<details>
<summary>По-русски</summary>

**Как это работает.**

1. Мерж в `main` запускает джобы `lint`, `phpstan`, `test` в GitHub Actions.
2. Если они прошли — джоба `release` запускает
   [semantic-release](https://semantic-release.gitbook.io/), которая читает
   [Conventional Commits](https://www.conventionalcommits.org/) с прошлого
   релиза: `fix:` поднимает patch-версию, `feat:` — minor, футер
   `BREAKING CHANGE:` — major. Остальное (`docs:`, `chore:`, `ci:` и т.п.)
   релиз не создаёт.
3. При релизе semantic-release обновляет `CHANGELOG.md`, поднимает
   `SDK_VERSION` в `src/GoBTCPay.php` / `src/GoBTCPayServer.php`, коммитит
   `chore(release): x.y.z [skip ci]`, ставит тег и создаёт GitHub release.
4. Этот пуш триггерит GitHub webhook (настроен в **Settings → Webhooks**
   этого репозитория), который стучится в `https://packagist.org/api/github`.
   Packagist сам подтягивает новый тег с GitHub и публикует версию — без
   ручного нажатия Update, без отдельного шага публикации.

Дистрибуция — через публичный [Packagist](https://packagist.org/), пакет
`gobtcpay/php-merchant-sdk`.

**Как передать этот пакет мерчанту.** Ничего особенного делать не нужно —
это обычный публичный Composer-пакет. Дайте ссылку на этот репозиторий или
на [страницу пакета на Packagist](https://packagist.org/packages/gobtcpay/php-merchant-sdk);
у себя в проекте мерчант просто выполняет
`composer require gobtcpay/php-merchant-sdk` и дальше следует разделам
[Installation](#installation) / [Quick start](#quick-start). Токен, приглашение
или согласование доступа не требуются. Ключи `apiKey` / `sk_live_…` выдаются
отдельно и никак не связаны с установкой пакета.

**Исключение — плагины для WordPress/WooCommerce/Shopify.** Такие мерчанты
обычно вообще не используют Composer, а прямое встраивание зависимостей SDK
(Guzzle, PSR-интерфейсы) рискует конфликтом имён классов с другими плагинами
на том же сайте. Плагин, встраивающий этот SDK, должен вендорить его на
этапе сборки инструментом с префиксацией неймспейсов
([php-scoper](https://github.com/humbug/php-scoper) или
[Strauss](https://github.com/BrianHenryIE/strauss)) и поставлять уже
префиксированный код внутри zip-архива плагина — мерчант напрямую с
Composer или этим пакетом не взаимодействует.

</details>

## License

**MIT** — see [LICENSE](./LICENSE). You may freely use, copy, modify, merge,
publish, distribute, sublicense, and sell the software, including in
closed-source and commercial products. Keep the copyright notice and license
text in copies. Provided "as is", without warranty.
