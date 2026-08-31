<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Tests\Unit;

use GoBTCPay\PosApiSdk\Signing;
use PHPUnit\Framework\TestCase;

final class SigningTest extends TestCase
{
    /** Independent reference implementation, used to cross-check the SDK's HMAC. */
    private static function refHmac(string $key, string $message): string
    {
        return hash_hmac('sha256', $message, $key);
    }

    public function testCanonicalizeSortsKeysAscending(): void
    {
        $a = Signing::canonicalize(['b' => 2, 'a' => 1, 'c' => 3]);
        $b = Signing::canonicalize(['c' => 3, 'a' => 1, 'b' => 2]);

        self::assertSame($a, $b);
        self::assertSame('{"a":1,"b":2,"c":3}', $a);
    }

    public function testCanonicalizeExcludesSignatureField(): void
    {
        self::assertSame(
            '{"amount":10}',
            Signing::canonicalize(['amount' => 10, 'signature' => 'deadbeef']),
        );
    }

    public function testCanonicalizePreservesNestedValuesVerbatim(): void
    {
        $out = Signing::canonicalize(['z' => ['y' => 1], 'a' => [3, 2, 1]]);
        self::assertSame('{"a":[3,2,1],"z":{"y":1}}', $out);
    }

    public function testCanonicalizeProducesEmptyObjectForEmptyParams(): void
    {
        self::assertSame('{}', Signing::canonicalize([]));
    }

    public function testSignMatchesIndependentHmac(): void
    {
        $params = ['amount' => 10, 'currency' => 'USD'];
        $key = 'terminal-secret';
        $expected = self::refHmac($key, Signing::canonicalize($params));

        self::assertSame($expected, Signing::sign($params, $key));
    }

    public function testSignIsDeterministic(): void
    {
        $params = ['posTerminalId' => 't1', 'amount' => 5];
        self::assertSame(Signing::sign($params, 'k'), Signing::sign($params, 'k'));
    }

    public function testSignChangesWithKey(): void
    {
        $params = ['amount' => 1];
        self::assertNotSame(Signing::sign($params, 'k1'), Signing::sign($params, 'k2'));
    }

    public function testSignIsInsensitiveToDeclaredKeyOrder(): void
    {
        self::assertSame(
            Signing::sign(['a' => 1, 'b' => 2], 'k'),
            Signing::sign(['b' => 2, 'a' => 1], 'k'),
        );
    }

    public function testSignReturns64CharLowercaseHex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Signing::sign(['a' => 1], 'k'));
    }

    public function testSignRequestAttachesTsAndSignatureOverParamsPlusTs(): void
    {
        $now = 1_700_000_000_000;
        $params = ['amount' => 10, 'currency' => 'USD'];
        $out = Signing::signRequest($params, 'secret', $now);

        self::assertSame($now, $out['ts']);
        self::assertSame(10, $out['amount']);

        // The signature must cover the timestamp, not just the original params.
        $expected = Signing::sign(['amount' => 10, 'currency' => 'USD', 'ts' => $now], 'secret');
        self::assertSame($expected, $out['signature']);
    }

    public function testSignRequestDoesNotMutateInput(): void
    {
        $params = ['amount' => 10];
        Signing::signRequest($params, 'secret', 1);

        self::assertSame(['amount' => 10], $params);
        self::assertArrayNotHasKey('ts', $params);
    }

    public function testSignRequestDiffersForDifferentTimestamp(): void
    {
        $params = ['amount' => 10];
        $a = Signing::signRequest($params, 'secret', 1);
        $b = Signing::signRequest($params, 'secret', 2);

        self::assertNotSame($a['signature'], $b['signature']);
    }

    public function testComputeWebhookSignatureOverTimestampDotBody(): void
    {
        $secret = 'whsec';
        $t = 1_700_000_000;
        $rawBody = '{"eventId":"e1"}';
        $expected = self::refHmac($secret, "{$t}.{$rawBody}");

        self::assertSame($expected, Signing::computeWebhookSignature($secret, $t, $rawBody));
    }

    public function testComputeWebhookSignatureTreatsNumericAndStringTimestampsIdentically(): void
    {
        $secret = 'whsec';
        $rawBody = '{}';

        self::assertSame(
            Signing::computeWebhookSignature($secret, 123, $rawBody),
            Signing::computeWebhookSignature($secret, '123', $rawBody),
        );
    }

    public function testComputeWebhookSignatureChangesWithBody(): void
    {
        $a = Signing::computeWebhookSignature('s', 1, '{"a":1}');
        $b = Signing::computeWebhookSignature('s', 1, '{"a":2}');

        self::assertNotSame($a, $b);
    }

    public function testSafeCompare(): void
    {
        self::assertTrue(Signing::safeCompare('abc123', 'abc123'));
        self::assertFalse(Signing::safeCompare('abc123', 'abc124'));
        self::assertFalse(Signing::safeCompare('abc', 'abcd'));
        self::assertTrue(Signing::safeCompare('', ''));
    }
}
