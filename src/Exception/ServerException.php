<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Exception;

/** 5xx — the API failed. Safe to retry idempotent calls. */
class ServerException extends ApiException
{
}
