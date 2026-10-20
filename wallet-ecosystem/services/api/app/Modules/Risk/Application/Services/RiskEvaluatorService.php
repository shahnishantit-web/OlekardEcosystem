<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Risk\Domain\Contracts\RiskEvaluatorInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\HoldStatus;
use App\Modules\Risk\Domain\Enums\RiskDecisionEnum;
use App\Modules\Risk\Domain\Enums\RiskLevel;
use App\Modules\Risk\Domain\Models\AccountHold;
use App\Modules\Risk\Domain\Models\RiskEvaluation;
use App\Modules\Risk\Domain\Models\RiskRule;
use App\Modules\Risk\Domain\ValueObjects\RiskEvaluationResult;
use App\Modules\Risk\Domain\ValueObjects\TransactionRiskContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RiskEvaluatorService implements RiskEvaluatorInterface
{
    public function __construct(
        private readonly RuleEngineService   $ruleEngine,
        private readonly AuditServiceInterface $audit,
        private readonly RiskMetricsInterface  $metrics,
    ) {}

    public function checkPreTransaction(TransactionRiskContext $ctx): RiskEvaluationResult
    {
        try {
            return $this->evaluate($ctx);
        } catch (\Throwable $e) {
            Log::error('Risk evaluation failed; defaulting to allow', [
                'idempotency_key' => $ctx->idempotencyKey,
                'error'           => $e->getMessage(),
            ]);

            $this->audit->record(new AuditEventData(
                action:        'risk.evaluation.unavailable',
                actorType:     'system',
                resourceType:  'risk_evaluation',
                correlationId: $ctx->idempotencyKey,
                outcome:       'failure',
                metadata:      ['error' => $e->getMessage(), 'customer_id' => $ctx->customerId],
            ));

            return new RiskEvaluationResult(
                evaluationId:  (string) Str::orderedUuid(),
                decision:      RiskDecisionEnum::Allow,
                riskLevel:     RiskLevel::Low,
                overallScore:  0.0,
                rulesApplied:  [],
                evaluationMs:  0,
            );
        }
    }

    private function evaluate(TransactionRiskContext $ctx): RiskEvaluationResult
    {
        $start = (int) (microtime(true) * 1000);

        // Idempotency check
        $existing = RiskEvaluation::where('idempotency_key', $ctx->idempotencyKey)->first();
        if ($existing !== null) {
            return new RiskEvaluationResult(
                evaluationId: (string) $existing->id,
                decision:     $existing->decision,
                riskLevel:    $existing->risk_level,
                overallScore: (float) $existing->overall_score,
                rulesApplied: $existing->rules_applied,
                evaluationMs: $existing->evaluation_ms,
            );
        }

        // Account hold check
        $hasHold = AccountHold::active()->where('subject_id', $ctx->customerId)->exists();
        if ($hasHold) {
            $ms     = (int) (microtime(true) * 1000) - $start;
            $evalId = (string) Str::orderedUuid();
            $this->persistEvaluation($evalId, $ctx, RiskDecisionEnum::Block, RiskLevel::Critical, 100.0, [], $ms);
            $this->metrics->evaluationTotal('block', 'critical', $ctx->transactionType);
            $this->metrics->evaluationDuration($ms, 'block');

            return new RiskEvaluationResult(
                evaluationId: $evalId,
                decision:     RiskDecisionEnum::Block,
                riskLevel:    RiskLevel::Critical,
                overallScore: 100.0,
                rulesApplied: [['rule_id' => null, 'rule_name' => 'account_hold', 'triggered' => true, 'score_contribution' => 100.0]],
                evaluationMs: $ms,
            );
        }

        // Load and apply rules
        $rules  = RiskRule::active()
            ->where(function ($q) use ($ctx): void {
                $q->where('applies_to', 'global')
                  ->orWhere(function ($q2) use ($ctx): void {
                      $q2->where('applies_to', 'customer')->where('subject_id', $ctx->customerId);
                  });
                if ($ctx->walletId) {
                    $q->orWhere(function ($q3) use ($ctx): void {
                        $q3->where('applies_to', 'wallet')->where('subject_id', $ctx->walletId);
                    });
                }
                if ($ctx->agentId) {
                    $q->orWhere(function ($q4) use ($ctx): void {
                        $q4->where('applies_to', 'agent')->where('subject_id', $ctx->agentId);
                    });
                }
            })
            ->get();

        $engineResult = $this->ruleEngine->applyRules($ctx, $rules->all());

        $ms       = (int) (microtime(true) * 1000) - $start;
        $evalId   = (string) Str::orderedUuid();
        $decision = $engineResult['decision'];
        $score    = $engineResult['overallScore'];
        $level    = RiskLevel::fromScore($score);

        $this->persistEvaluation($evalId, $ctx, $decision, $level, $score, $engineResult['rulesApplied'], $ms);
        $this->metrics->evaluationTotal($decision->value, $level->value, $ctx->transactionType);
        $this->metrics->evaluationDuration($ms, $decision->value);

        foreach ($engineResult['rulesApplied'] as $r) {
            if ($r['triggered']) {
                $this->metrics->ruleTriggered($r['rule_name'], $r['rule_type']);
            }
        }

        return new RiskEvaluationResult(
            evaluationId: $evalId,
            decision:     $decision,
            riskLevel:    $level,
            overallScore: $score,
            rulesApplied: $engineResult['rulesApplied'],
            evaluationMs: $ms,
        );
    }

    private function persistEvaluation(
        string $id,
        TransactionRiskContext $ctx,
        RiskDecisionEnum $decision,
        RiskLevel $level,
        float $score,
        array $rulesApplied,
        int $ms,
    ): void {
        RiskEvaluation::create([
            'id'                    => $id,
            'idempotency_key'       => $ctx->idempotencyKey,
            'transaction_reference' => $ctx->transactionReference,
            'transaction_type'      => $ctx->transactionType,
            'amount'                => $ctx->amount,
            'currency'              => $ctx->currency,
            'customer_id'           => $ctx->customerId,
            'wallet_id'             => $ctx->walletId,
            'agent_id'              => $ctx->agentId,
            'device_id'             => $ctx->deviceId,
            'ip_address'            => $ctx->ipAddress,
            'beneficiary_id'        => $ctx->beneficiaryId,
            'kyc_tier'              => $ctx->kycTier,
            'overall_score'         => $score,
            'risk_level'            => $level->value,
            'decision'              => $decision->value,
            'evaluation_ms'         => $ms,
            'evaluated_at'          => now(),
            'rules_applied'         => $rulesApplied,
        ]);
    }
}
