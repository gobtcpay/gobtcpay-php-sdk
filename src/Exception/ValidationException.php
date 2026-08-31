<?php

declare(strict_types=1);

namespace GoBTCPay\PosApiSdk\Exception;

/** 400 / 402 — the request was rejected. Retrying it unchanged will not help. */
class ValidationException extends ApiException
{
}
