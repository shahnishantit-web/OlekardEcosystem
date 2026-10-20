<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Exceptions;

use RuntimeException;

final class AlertRuleNotFoundException extends RuntimeException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(string $id)
    {
        parent::__construct("Alert rule '{$id}' not found.");
        $this->errorCode  = 'OPS_ALERT_RULE_NOT_FOUND';
        $this->httpStatus = 404;
    }
}
