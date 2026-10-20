<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Contracts;

use App\Modules\Risk\Domain\ValueObjects\RiskEvaluationResult;
use App\Modules\Risk\Domain\ValueObjects\TransactionRiskContext;

interface RiskEvaluatorInterface
{
    public function checkPreTransaction(TransactionRiskContext $ctx): RiskEvaluationResult;
}
