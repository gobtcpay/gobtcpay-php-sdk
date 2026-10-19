<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Exception;

use Throwable;

/**
 * Thrown by `GoBTCPayServer::findWebhookByUrl()` when it scanned as many pages
 * as it is allowed to and the URL has not turned up.
 *
 * This is **not** "no such URL": the endpoints past the limit were never
 * looked at, so the answer is unknown. Treating it as "not registered" and
 * creating an endpoint would risk registering the same URL twice — which is
 * the reason this is an exception and not a `null`.
 *
 * It extends {@see GoBTCPayException} directly rather than {@see ApiException}:
 * no API response was an error here, the search ran out of budget.
 */
class WebhookSearchLimitException extends GoBTCPayException
{
    /**
     * @param int $pagesScanned Pages read before stopping.
     * @param int $scannedCount Endpoints examined across those pages.
     * @param int $totalCount Endpoint count the server reported on the last page read.
     *                        Informational only: the server computes it separately
     *                        from the page, and the search never relied on it to stop.
     */
    public function __construct(
        public readonly int $pagesScanned,
        public readonly int $scannedCount,
        public readonly int $totalCount,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Webhook endpoint search stopped at its limit after %d pages (%d endpoints examined; the server reports %d in total) without finding the URL. '
                . 'The URL may still be registered further on — this is not a "not found".',
                $pagesScanned,
                $scannedCount,
                $totalCount,
            ),
            0,
            $previous,
        );
    }
}
