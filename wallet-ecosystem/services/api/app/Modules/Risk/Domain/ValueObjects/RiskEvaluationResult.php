<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\ValueObjects;

use App\Modules\Risk\Domain\Enums\RiskDecisionEnum;
use App\Modules\Risk\Domain\Enums\RiskLevel;

final readonly class RiskEvaluationResult
{
    /**
     * @param array<int, array<string, mixed>> $rulesApplied
     */
    public function __construct(
        public string          $evaluationId,
        public RiskDecisionEnum $decision,
        public RiskLevel        $riskLevel,
        public float            $overallScore,
        public array            $rulesApplied,
        public int              $evaluationMs,
    ) {}
}
