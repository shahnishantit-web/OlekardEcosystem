<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Exceptions;

use RuntimeException;

final class FeeRuleNotFoundException extends RuntimeException
{
    public function __construct(string $ruleId)
    {
        parent::__construct("Fee rule [{$ruleId}] not found.");
    }
}
