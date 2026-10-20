<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Risk\Application\Commands\ConfigureRiskRuleCommand;
use App\Modules\Risk\Domain\Enums\RiskDecisionEnum;
use App\Modules\Risk\Domain\Enums\RuleType;
use App\Modules\Risk\Domain\Models\RiskRule;
use App\Modules\Risk\Domain\ValueObjects\TransactionRiskContext;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class RuleEngineService
{
    public function __construct(
        private readonly VelocityAccumulatorService    $velocity,
        private readonly ConfigurationServiceInterface $config,
        private readonly AuditServiceInterface         $audit,
    ) {}

    /**
     * @return array{rulesApplied: array, overallScore: float, decision: RiskDecisionEnum}
     */
    public function applyRules(TransactionRiskContext $ctx, array $rules): array
    {
        $rulesApplied = [];
        $totalScore   = 0.0;

        foreach ($rules as $rule) {
            $result = $this->evaluateRule($rule, $ctx);
            $rulesApplied[] = $result;

            if ($result['triggered']) {
                $totalScore += $result['score_contribution'];
            }
        }

        $overallScore = min(100.0, $totalScore);

        // Triggered rules with explicit block action override score-based decision
        $forceBlock = false;
        foreach ($rulesApplied as $r) {
            if ($r['triggered'] && $r['action'] === RiskDecisionEnum::Block->value) {
                $forceBlock = true;
                break;
            }
        }

        $decision = $forceBlock ? RiskDecisionEnum::Block : $this->scoreToDecision($overallScore);

        return [
            'rulesApplied' => $rulesApplied,
            'overallScore' => $overallScore,
            'decision'     => $decision,
        ];
    }

    public function configureRule(ConfigureRiskRuleCommand $cmd): RiskRule
    {
        $actorId = $cmd->actorId ?? '00000000-0000-0000-0000-000000000000';
        $name    = $cmd->deriveName();

        $existing = RiskRule::where('name', $name)
            ->where(function ($q) use ($cmd): void {
                if ($cmd->appliesToId !== null) {
                    $q->where('subject_id', $cmd->appliesToId);
                } else {
                    $q->whereNull('subject_id');
                }
            })
            ->where('is_active', true)
            ->first();

        $isUpdate = $existing !== null;
        if ($isUpdate) {
            $existing->update(['is_active' => false, 'updated_by' => $actorId]);
        }

        $rule = RiskRule::create([
            'id'                => (string) Str::orderedUuid(),
            'name'              => $name,
            'description'       => $cmd->description,
            'rule_type'         => $cmd->ruleType->value,
            'applies_to'        => $cmd->appliesToType,
            'subject_id'        => $cmd->appliesToId,
            'threshold_amount'  => $cmd->thresholdAmount,
            'threshold_count'   => $cmd->thresholdCount,
            'window_seconds'    => $cmd->windowSeconds,
            'risk_score_weight' => $cmd->riskScoreWeight,
            'action'            => $cmd->action,
            'is_active'         => true,
            'effective_from'    => $cmd->effectiveFrom ?? now()->toIso8601String(),
            'effective_until'   => $cmd->effectiveUntil,
            'created_by'        => $actorId,
            'updated_by'        => $actorId,
        ]);

        $this->audit->record(new AuditEventData(
            action:        $isUpdate ? 'risk.rule.updated' : 'risk.rule.created',
            actorType:     'user',
            resourceType:  'risk_rule',
            correlationId: (string) $rule->id,
            outcome:       'success',
            metadata:      [
                'rule_id'      => $rule->id,
                'rule_name'    => $name,
                'applies_to'   => $cmd->appliesToType,
                'subject_id'   => $cmd->appliesToId,
                'replaced_id'  => $existing?->id,
            ],
        ));

        return $rule;
    }

    public function recordAuthFailure(string $subjectType, string $subjectId, string $deviceId): int
    {
        $rule = RiskRule::active()
            ->where('rule_type', RuleType::AuthFailureCount->value)
            ->where(function ($q) use ($subjectId): void {
                $q->where('subject_id', $subjectId)->orWhere('applies_to', 'global');
            })
            ->orderByRaw("CASE WHEN subject_id = ? THEN 0 ELSE 1 END", [$subjectId])
            ->first();

        $windowSeconds = $rule?->window_seconds ?? 600;
        $redisKey      = "auth_fail:{$subjectType}:{$subjectId}:{$deviceId}:{$windowSeconds}";

        $redis = Redis::connection();
        $count = $redis->incr($redisKey);

        if ($count === 1) {
            $redis->expire($redisKey, $windowSeconds);
        }

        return (int) $count;
    }

    private function evaluateRule(RiskRule $rule, TransactionRiskContext $ctx): array
    {
        $triggered         = false;
        $scoreContribution = 0.0;

        switch ($rule->rule_type) {
            case RuleType::AmountLimit:
                if ($rule->threshold_amount !== null) {
                    $triggered = bccomp($ctx->amount, (string) $rule->threshold_amount, 4) > 0;
                }
                break;

            case RuleType::VelocityAmount:
                if ($rule->window_seconds && $rule->threshold_amount !== null) {
                    $key     = $this->velocity->buildKey($ctx->customerId, $ctx->transactionType, $rule->window_seconds);
                    $current = $this->velocity->getAmount($key, $rule->window_seconds);
                    $triggered = bccomp(bcadd($current, $ctx->amount, 4), (string) $rule->threshold_amount, 4) > 0;
                }
                break;

            case RuleType::VelocityCount:
                if ($rule->window_seconds && $rule->threshold_count !== null) {
                    $key   = $this->velocity->buildKey($ctx->customerId, $ctx->transactionType, $rule->window_seconds);
                    $count = $this->velocity->getCount($key, $rule->window_seconds);
                    $triggered = $count >= $rule->threshold_count;
                }
                break;

            case RuleType::DeviceScore:
                $triggered = $ctx->isNewDevice && $rule->threshold_count !== null;
                break;

            case RuleType::IpScore:
                if ($ctx->ipAddress !== null && $rule->threshold_amount !== null) {
                    if ($rule->subject_id !== null) {
                        // IP-specific rule: match exact IP in subject_id
                        $triggered = $ctx->ipAddress === $rule->subject_id;
                    } else {
                        // Global IpScore rule: elevate score for any transaction with an IP
                        $triggered = $rule->applies_to === 'global';
                    }
                }
                break;

            case RuleType::BeneficiaryScore:
                $triggered = $ctx->isNewBeneficiary && $rule->threshold_amount !== null;
                break;

            case RuleType::AuthFailureCount:
                // handled via recordAuthFailure separately
                break;
        }

        if ($triggered) {
            $scoreContribution = (float) $rule->risk_score_weight * 20.0;
        }

        return [
            'rule_id'           => $rule->id,
            'rule_name'         => $rule->name,
            'rule_type'         => $rule->rule_type->value,
            'triggered'         => $triggered,
            'score_contribution' => $scoreContribution,
            'action'            => $rule->action->value,
        ];
    }

    private function scoreToDecision(float $score): RiskDecisionEnum
    {
        $blockThreshold  = (float) $this->config->getOrDefault('risk.block_score_threshold', 85);
        $reviewThreshold = (float) $this->config->getOrDefault('risk.review_score_threshold', 50);

        if ($score >= $blockThreshold) {
            return RiskDecisionEnum::Block;
        }

        if ($score >= $reviewThreshold) {
            return RiskDecisionEnum::Review;
        }

        return RiskDecisionEnum::Allow;
    }

}
