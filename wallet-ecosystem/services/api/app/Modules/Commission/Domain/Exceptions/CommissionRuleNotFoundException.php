<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Exceptions;

final class CommissionRuleNotFoundException extends \DomainException
{
    public readonly string $errorCode;

    public function __construct(string $ruleId)
    {
        parent::__construct("Commission rule [{$ruleId}] not found.");
        $this->errorCode = 'COMMISSION_RULE_NOT_FOUND';
    }
}
