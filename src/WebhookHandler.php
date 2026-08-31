<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk;

use GoBTCPay\PosApiSdk\Dto\WebhookEvent;
use GoBTCPay\PosApiSdk\Exception\WebhookSignatureException;

/**
 * Parses, verifies and dispatches GoBTC Pay webhook deliveries.
 *
 * Subscribe to events with {@see on}, then feed raw deliveries through
 * {@see handle}. Signature verification, replay-window checks and `eventId`
 * de-duplication are handled for you.
 */
final class WebhookHandler
{
    public const SIGNATURE_HEADER = 'X-GoBTCPay-Signature';

    private readonly string $signingSecret;
    private readonly int $toleranceSeconds;
    private readonly int $dedupeCacheSize;

    /**
     * Recently seen event ids, most-recent last. Backing an LRU: PHP arrays
     * preserve insertion order, so the first key is the oldest.
     *
     * @var array<string, true>
     */
    private array $seen = [];

    /**
     * @var array<string, list<callable(WebhookEvent): void>>
     */
    private array $listeners = [];

    /**
     * @param string $signingSecret Signing secret shown once when the webhook endpoint is created.
     * @param int $toleranceSeconds Max age of a delivery before its signature is rejected
     *                              (replay protection). Defaults to 300 (5 min). 0 disables.
     * @param int $dedupeCacheSize Number of recently-seen `eventId`s to remember for
     *                             de-duplication. Defaults to 1000. 0 disables in-memory de-dup.
     */
    public function __construct(
        string $signingSecret,
        int $toleranceSeconds = 300,
        int $dedupeCacheSize = 1000,
    ) {
        $this->signingSecret = $signingSecret;
        $this->toleranceSeconds = $toleranceSeconds;
        $this->dedupeCacheSize = $dedupeCacheSize;
    }

    /**
     * Subscribe to a webhook event type. Returns an unsubscribe callable.
     *
     * @param callable(WebhookEvent): void $listener
     *
     * @return callable(): void
     */
    public function on(string $type, callable $listener): callable
    {
        $this->listeners[$type][] = $listener;
        $index = array_key_last($this->listeners[$type]);

        return function () use ($type, $index): void {
            unset($this->listeners[$type][$index]);
        };
    }

    /**
     * Verify a delivery's signature and parse it into a typed event.
     *
     * @param string $rawBody The exact raw request body string (do not re-serialize).
     * @param string|null $signatureHeader Value of the `X-GoBTCPay-Signature` header.
     *
     * @throws WebhookSignatureException if the header is missing/malformed, the timestamp is
     *                                   outside the tolerance window, or the signature does not match.
     */
    public function constructEvent(string $rawBody, ?string $signatureHeader): WebhookEvent
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new WebhookSignatureException('Missing X-GoBTCPay-Signature header');
        }

        ['t' => $t, 'v1' => $v1] = self::parseSignatureHeader($signatureHeader);
        if ($t === null || $v1 === null) {
            throw new WebhookSignatureException('Malformed X-GoBTCPay-Signature header');
        }

        if ($this->toleranceSeconds > 0) {
            if (!is_numeric($t)) {
                throw new WebhookSignatureException('Webhook timestamp outside tolerance');
            }
            $ageSeconds = abs(microtime(true) - (float) $t);
            if ($ageSeconds > $this->toleranceSeconds) {
                throw new WebhookSignatureException('Webhook timestamp outside tolerance');
            }
        }

        $expected = Signing::computeWebhookSignature($this->signingSecret, $t, $rawBody);
        if (!Signing::safeCompare($expected, $v1)) {
            throw new WebhookSignatureException('Webhook signature mismatch');
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new WebhookSignatureException('Webhook body is not valid JSON');
        }

        /** @var array<string, mixed> $decoded */
        return WebhookEvent::fromArray($decoded);
    }

    /**
     * Verify, de-duplicate and dispatch a delivery to all subscribers.
     *
     * @return WebhookEvent|null The parsed event, or null if it was a duplicate (already seen).
     *
     * @throws WebhookSignatureException if verification fails.
     */
    public function handle(string $rawBody, ?string $signatureHeader): ?WebhookEvent
    {
        $event = $this->constructEvent($rawBody, $signatureHeader);

        if ($this->dedupeCacheSize > 0) {
            if (isset($this->seen[$event->eventId])) {
                return null;
            }
            $this->remember($event->eventId);
        }

        foreach ($this->listeners[$event->type] ?? [] as $listener) {
            $listener($event);
        }

        return $event;
    }

    private function remember(string $eventId): void
    {
        $this->seen[$eventId] = true;
        if (count($this->seen) > $this->dedupeCacheSize) {
            // Evict the oldest entry (arrays preserve insertion order).
            array_shift($this->seen);
        }
    }

    /**
     * Parse a `t={ts},v1={hex}` header into its parts.
     *
     * @return array{t: string|null, v1: string|null}
     */
    private static function parseSignatureHeader(string $header): array
    {
        $out = ['t' => null, 'v1' => null];
        foreach (explode(',', $header) as $part) {
            $idx = strpos($part, '=');
            if ($idx === false) {
                continue;
            }
            $key = trim(substr($part, 0, $idx));
            $value = trim(substr($part, $idx + 1));
            if ($key === 't') {
                $out['t'] = $value;
            } elseif ($key === 'v1') {
                $out['v1'] = $value;
            }
        }

        return $out;
    }
}
