<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Dto;

/**
 * The envelope returned by every endpoint:
 *
 * `{ id, result: { $case: "success", success: T } | { $case: "failure", failure: { code, message, data } } }`
 *
 * Used internally by {@see \GoBTCPay\PosApiSdk\Transport} to unwrap responses.
 */
final class ApiEnvelope
{
    /**
     * @param mixed $success The `result.success` payload, when successful.
     * @param array<string, mixed>|null $failure The `result.failure` body, when failed.
     */
    private function __construct(
        public readonly string $id,
        public readonly bool $isSuccess,
        public readonly mixed $success = null,
        public readonly ?array $failure = null,
    ) {
    }

    /**
     * Parse a decoded JSON body into an envelope.
     *
     * Returns null when the body is not a recognizable envelope (no `result`),
     * so the transport can fall back to a generic error keyed on HTTP status.
     *
     * @param array<string, mixed> $decoded
     */
    public static function fromDecoded(array $decoded): ?self
    {
        $id = (string) ($decoded['id'] ?? '');
        $result = $decoded['result'] ?? null;
        if (!is_array($result) || !isset($result['$case'])) {
            return null;
        }

        if ($result['$case'] === 'success') {
            return new self($id, true, $result['success'] ?? null);
        }

        if ($result['$case'] === 'failure') {
            /** @var array<string, mixed> $failure */
            $failure = is_array($result['failure'] ?? null) ? $result['failure'] : [];

            return new self($id, false, null, $failure);
        }

        return null;
    }
}
