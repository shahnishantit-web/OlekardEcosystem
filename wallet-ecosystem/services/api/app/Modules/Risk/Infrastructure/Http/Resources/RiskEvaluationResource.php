<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Resources;

use App\Modules\Risk\Domain\ValueObjects\RiskEvaluationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RiskEvaluationResult
 */
final class RiskEvaluationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var RiskEvaluationResult $result */
        $result = $this->resource;

        return [
            'evaluation_id'  => $result->evaluationId,
            'decision'       => $result->decision->value,
            'risk_level'     => $result->riskLevel->value,
            'overall_score'  => $result->overallScore,
            'rules_applied'  => $result->rulesApplied,
            'evaluation_ms'  => $result->evaluationMs,
        ];
    }
}
