<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Infrastructure\Stubs;

use App\Modules\Withdrawal\Application\Contracts\WithdrawalRiskResult;
use App\Modules\Withdrawal\Application\Contracts\WithdrawalRiskService;

final class WithdrawalRiskServiceStub implements WithdrawalRiskService
{
    public function evaluate(array $context): WithdrawalRiskResult
    {
        return WithdrawalRiskResult::approved();
    }
}
