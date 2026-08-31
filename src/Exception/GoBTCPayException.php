<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Exception;

use RuntimeException;

/**
 * Base class for every error thrown by the SDK.
 *
 * Catch this to handle any SDK failure in one place; catch a subclass when you
 * want to react to a specific failure mode.
 */
class GoBTCPayException extends RuntimeException
{
}
