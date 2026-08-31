<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Exception;

use Throwable;

/**
 * The request never produced a response: connection failure, DNS, TLS, or a
 * client-side timeout / abort.
 */
class NetworkException extends GoBTCPayException
{
    /**
     * @param bool $isTimeout True when the request was aborted by a timeout.
     */
    public function __construct(
        string $message,
        public readonly bool $isTimeout = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
