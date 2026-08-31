<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk;

/**
 * HMAC-SHA256 signing layer, built on PHP's native `hash_hmac`.
 *
 * Two schemes live here:
 *
 * - Per-request signing for POS terminals: the whole params object is
 *   canonicalized (keys sorted, `signature` excluded) and signed with the
 *   terminal key, then `ts` + `signature` are attached.
 * - Webhook signatures: `HMAC-SHA256(secret, "{t}.{rawBody}")`.
 *
 * All methods are static and pure.
 */
final class Signing
{
    /**
     * Serialize a params object into the canonical message used for signing:
     * keys sorted ascending, then JSON-encoded. The `signature` field is always
     * excluded. Matches the reference SDK's `JSON.stringify` byte-for-byte for
     * the value shapes the API accepts (no unnecessary whitespace, forward
     * slashes and unicode left unescaped).
     *
     * @param array<string, mixed> $params
     */
    public static function canonicalize(array $params): string
    {
        $sorted = [];
        $keys = array_keys($params);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            if ($key === 'signature') {
                continue;
            }
            $sorted[$key] = $params[$key];
        }

        $json = json_encode(
            $sorted,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        // An empty associative array must serialize to `{}`, not `[]`, to match
        // the JS canonical form used on the signing side of the API.
        if ($sorted === []) {
            return '{}';
        }

        return $json;
    }

    /**
     * Compute the per-request HMAC-SHA256 signature (lowercase hex) over the
     * canonical representation of `$params`, keyed by the terminal `$apiKey`.
     *
     * @param array<string, mixed> $params
     */
    public static function sign(array $params, string $apiKey): string
    {
        return hash_hmac('sha256', self::canonicalize($params), $apiKey);
    }

    /**
     * Return a copy of `$params` with `ts` (current time in ms) and a freshly
     * computed `signature` attached. Every request is signed at the moment it is
     * sent, which is what makes the auth "real-time".
     *
     * @param array<string, mixed> $params
     * @param int|null $now Milliseconds since the epoch. Defaults to now.
     *
     * @return array<string, mixed>
     */
    public static function signRequest(array $params, string $apiKey, ?int $now = null): array
    {
        $now ??= (int) round(microtime(true) * 1000);
        $withTs = $params;
        $withTs['ts'] = $now;
        $withTs['signature'] = self::sign($withTs, $apiKey);

        return $withTs;
    }

    /** Compute the webhook signature `v1 = HMAC-SHA256(secret, "{t}.{rawBody}")`. */
    public static function computeWebhookSignature(
        string $signingSecret,
        int|string $timestamp,
        string $rawBody,
    ): string {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $signingSecret);
    }

    /** Constant-time comparison of two hex strings. */
    public static function safeCompare(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
