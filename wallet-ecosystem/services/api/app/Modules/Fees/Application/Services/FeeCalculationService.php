<?php

declare(strict_types=1);

namespace App\Modules\Fees\Application\Services;

use App\Modules\Fees\Domain\Enums\FeeRuleStatus;
use App\Modules\Fees\Domain\Enums\FeeSubjectType;
use App\Modules\Fees\Domain\Enums\FeeType;
use App\Modules\Fees\Domain\Exceptions\FeeCalculationException;
use App\Modules\Fees\Domain\ValueObjects\FeeCalculationResult;
use App\Modules\Fees\Infrastructure\Models\FeeRuleModel;

final class FeeCalculationService
{
    /**
     * Resolve the active fee rule using precedence: agent > customer > global.
     */
    public function resolveRule(
        string  $transactionType,
        string  $currency,
        string  $subjectType,
        ?string $subjectId,
    ): ?FeeRuleModel {
        $now = now();

        $baseQuery = static fn () => FeeRuleModel::where('transaction_type', $transactionType)
            ->where('currency', $currency)
            ->where('status', FeeRuleStatus::Active->value)
            ->where('valid_from', '<=', $now)
            ->where(static function ($q) use ($now): void {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now);
            });

        if ($subjectType === FeeSubjectType::Agent->value && $subjectId !== null) {
            $rule = $baseQuery()->where('subject_type', FeeSubjectType::Agent->value)
                ->where('subject_id', $subjectId)
                ->first();
            if ($rule !== null) {
                return $rule;
            }
        }

        if ($subjectType === FeeSubjectType::Customer->value && $subjectId !== null) {
            $rule = $baseQuery()->where('subject_type', FeeSubjectType::Customer->value)
                ->where('subject_id', $subjectId)
                ->first();
            if ($rule !== null) {
                return $rule;
            }
        }

        return $baseQuery()->where('subject_type', FeeSubjectType::Global->value)
            ->whereNull('subject_id')
            ->first();
    }

    /**
     * Calculate the fee for a given transaction context.
     */
    public function calculate(
        string  $transactionType,
        int     $amountMinor,
        string  $currency,
        string  $subjectType,
        ?string $subjectId,
    ): FeeCalculationResult {
        $rule = $this->resolveRule($transactionType, $currency, $subjectType, $subjectId);

        if ($rule === null) {
            return FeeCalculationResult::zero();
        }

        return match ($rule->fee_type) {
            FeeType::Percentage => $this->calculatePercentageFee(
                $amountMinor,
                (float) $rule->rate,
                $rule->min_fee_minor,
                $rule->max_fee_minor,
                $rule->id,
            ),
            FeeType::Fixed => $this->calculateFixedFee(
                (int) $rule->amount_minor,
                $rule->id,
            ),
        };
    }

    /**
     * Calculate percentage fee with half-up rounding.
     * Uses bcmath when available (ext-bcmath), falls back to round() for integer minor amounts.
     */
    public function calculatePercentageFee(
        int    $amountMinor,
        float  $rate,
        ?int   $minFeeMinor,
        ?int   $maxFeeMinor,
        string $ruleId,
    ): FeeCalculationResult {
        if (function_exists('bcadd')) {
            $calculated = (int) \bcadd(
                \bcdiv(\bcmul((string) $amountMinor, (string) $rate, 10), '100', 10),
                '0.5',
                0
            );
        } else {
            // Half-up rounding on integer minor-unit amounts; no IEEE 754 drift for amounts < 10^15
            $calculated = (int) round($amountMinor * $rate / 100, 0, PHP_ROUND_HALF_UP);
        }

        $minApplied = false;
        $maxApplied = false;

        if ($minFeeMinor !== null && $calculated < $minFeeMinor) {
            $calculated = $minFeeMinor;
            $minApplied = true;
        }

        if ($maxFeeMinor !== null && $calculated > $maxFeeMinor) {
            $calculated = $maxFeeMinor;
            $maxApplied = true;
        }

        return new FeeCalculationResult(
            feeAmountMinor:    $calculated,
            feeRuleId:         $ruleId,
            feeType:           FeeType::Percentage->value,
            hasApplicableRule: true,
            minFeeApplied:     $minApplied,
            maxFeeApplied:     $maxApplied,
        );
    }

    /**
     * Return the rule's fixed amount regardless of transaction amount.
     */
    public function calculateFixedFee(int $ruleAmountMinor, string $ruleId): FeeCalculationResult
    {
        return new FeeCalculationResult(
            feeAmountMinor:    $ruleAmountMinor,
            feeRuleId:         $ruleId,
            feeType:           FeeType::Fixed->value,
            hasApplicableRule: true,
            minFeeApplied:     false,
            maxFeeApplied:     false,
        );
    }
}
