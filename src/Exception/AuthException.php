<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Exception;

/** 401 / 403 — the API key is missing, malformed, revoked or not permitted. */
class AuthException extends ApiException
{
}
