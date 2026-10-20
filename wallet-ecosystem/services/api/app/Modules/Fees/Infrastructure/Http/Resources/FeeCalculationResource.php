<?php

declare(strict_types=1);

namespace App\Modules\Fees\Infrastructure\Http\Resources;

use App\Modules\Fees\Domain\ValueObjects\FeeCalculationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeeCalculationResult */
final class FeeCalculationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'fee_amount_minor'   => $this->feeAmountMinor,
            'fee_type'           => $this->feeType,
            'rule_id'            => $this->feeRuleId,
            'has_applicable_rule'=> $this->hasApplicableRule,
            'min_fee_applied'    => $this->minFeeApplied,
            'max_fee_applied'    => $this->maxFeeApplied,
        ];
    }
}
