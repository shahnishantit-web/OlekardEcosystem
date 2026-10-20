<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Stubs;

use App\Modules\Transfer\Application\Contracts\RiskResult;
use App\Modules\Transfer\Application\Contracts\TransferRiskService;

/**
 * Stub implementation of TransferRiskService.
 * Always returns approved. E18 (Risk Engine) overrides this binding.
 */
final class TransferRiskServiceStub implements TransferRiskService
{
    public function evaluate(array $context): RiskResult
    {
        return RiskResult::approved();
    }
}
