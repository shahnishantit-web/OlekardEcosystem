<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Controllers;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Risk\Application\Commands\EvaluateTransactionRiskCommand;
use App\Modules\Risk\Application\Services\AlertService;
use App\Modules\Risk\Application\Services\RuleEngineService;
use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use App\Modules\Risk\Application\Services\WatchlistScreenerService;
use App\Modules\Risk\Domain\Contracts\RiskEvaluatorInterface;
use App\Modules\Risk\Domain\Enums\AlertStatus;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Enums\RiskLevel;
use App\Modules\Risk\Domain\Enums\RuleType;
use App\Modules\Risk\Domain\Models\RiskAlert;
use App\Modules\Risk\Domain\Models\RiskDecision;
use App\Modules\Risk\Domain\Models\RiskEvaluation;
use App\Modules\Risk\Domain\Models\RiskRule;
use App\Modules\Risk\Infrastructure\Http\Requests\BeneficiaryRiskRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\EvaluateAmountThresholdRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\EvaluateDeviceRiskRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\EvaluateTransactionRiskRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\EvaluateVelocityRequest;
use App\Modules\Risk\Application\Jobs\DetectRapidFundMovementJob;
use App\Modules\Risk\Application\Jobs\DetectUnusualPatternJob;
use App\Modules\Risk\Infrastructure\Http\Requests\AuthFailureRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\IpRiskRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\ManualDecisionRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\PatternDetectionRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\SendForReviewRequest;
use App\Modules\Risk\Infrastructure\Http\Resources\ManualDecisionResource;
use App\Modules\Risk\Infrastructure\Http\Resources\RiskAlertResource;
use App\Modules\Risk\Infrastructure\Http\Resources\RiskEvaluationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class RiskEvaluationController extends Controller
{
    private const SYSTEM_UUID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly RiskEvaluatorInterface $evaluator,
        private readonly VelocityAccumulatorService $velocity,
        private readonly RuleEngineService $ruleEngine,
        private readonly WatchlistScreenerService $watchlistScreener,
        private readonly AlertService $alertService,
        private readonly AuditServiceInterface $audit,
    ) {}

    // ── T049: Full synchronous evaluation ────────────────────────────────────

    public function evaluateTransactionRisk(EvaluateTransactionRiskRequest $request): RiskEvaluationResource
    {
        $command = new EvaluateTransactionRiskCommand(
            idempotencyKey:       $request->string('idempotency_key')->value(),
            transactionReference: $request->string('transaction_reference')->value(),
            transactionType:      $request->string('transaction_type')->value(),
            amount:               $request->string('amount')->value(),
            currency:             $request->string('currency')->value(),
            customerId:           $request->string('customer_id')->value(),
            walletId:             $request->input('wallet_id'),
            agentId:              $request->input('agent_id'),
            beneficiaryId:        $request->input('beneficiary_id'),
            deviceId:             $request->input('device_id'),
            ipAddress:            $request->input('ip_address'),
            kycTier:              $request->input('kyc_tier'),
            isNewDevice:          (bool) $request->input('is_new_device', false),
            isNewBeneficiary:     (bool) $request->input('is_new_beneficiary', false),
        );

        $result = $this->evaluator->checkPreTransaction($command->toContext());

        return new RiskEvaluationResource($result);
    }

    // ── T050: Velocity window check ───────────────────────────────────────────

    public function evaluateTransactionVelocity(EvaluateVelocityRequest $request): JsonResponse
    {
        $customerId     = $request->string('customer_id')->value();
        $transactionType = $request->string('transaction_type')->value();
        $windowSeconds  = (int) $request->input('window_seconds');

        $key    = $this->velocity->buildKey($customerId, $transactionType, $windowSeconds);
        $count  = $this->velocity->getCount($key, $windowSeconds);
        $amount = $this->velocity->getAmount($key, $windowSeconds);

        return response()->json([
            'customer_id'      => $customerId,
            'transaction_type' => $transactionType,
            'window_seconds'   => $windowSeconds,
            'count'            => $count,
            'total_amount'     => $amount,
        ]);
    }

    // ── T051: Per-transaction amount threshold check ──────────────────────────

    public function evaluateAmountThreshold(EvaluateAmountThresholdRequest $request): JsonResponse
    {
        $amount    = $request->string('amount')->value();
        $subjectId = $request->input('subject_id');

        $rules = RiskRule::active()
            ->where('rule_type', RuleType::AmountLimit->value)
            ->where(function ($q) use ($subjectId): void {
                $q->where('applies_to', 'global');
                if ($subjectId) {
                    $q->orWhere('subject_id', $subjectId);
                }
            })
            ->get();

        $exceededRules = $rules
            ->filter(fn (RiskRule $r) => $r->threshold_amount !== null
                && bccomp($amount, (string) $r->threshold_amount, 4) > 0)
            ->map(fn (RiskRule $r) => [
                'rule_id'          => $r->id,
                'rule_name'        => $r->name,
                'threshold_amount' => $r->threshold_amount,
                'action'           => $r->action->value,
            ])
            ->values();

        return response()->json([
            'amount'         => $amount,
            'triggered'      => $exceededRules->isNotEmpty(),
            'exceeded_rules' => $exceededRules,
        ]);
    }

    // ── T052: Device risk assessment ──────────────────────────────────────────

    public function evaluateDeviceRisk(EvaluateDeviceRiskRequest $request): JsonResponse
    {
        $deviceId   = $request->string('device_id')->value();
        $customerId = $request->string('customer_id')->value();
        $isNew      = (bool) $request->input('is_new_device');

        $authFailKey   = "auth_fail:customer:{$customerId}:{$deviceId}:*";
        $authFailCount = 0;

        foreach (Redis::connection()->keys($authFailKey) as $key) {
            $authFailCount += (int) Redis::connection()->get($key);
        }

        $rule = RiskRule::active()
            ->where('rule_type', RuleType::DeviceScore->value)
            ->orderByRaw("CASE WHEN applies_to = 'global' THEN 1 ELSE 0 END")
            ->first();

        $baseScore  = $isNew ? 30.0 : 0.0;
        $failScore  = min(40.0, $authFailCount * 10.0);
        $totalScore = min(100.0, $baseScore + $failScore);

        return response()->json([
            'device_id'        => $deviceId,
            'customer_id'      => $customerId,
            'is_new_device'    => $isNew,
            'auth_failure_count' => $authFailCount,
            'risk_score'       => $totalScore,
            'rule_applied'     => $rule?->name,
        ]);
    }

    // ── Stubs for later phases (T058–T092) ────────────────────────────────────

    public function evaluateIpRisk(IpRiskRequest $request): JsonResponse
    {
        $ipAddress  = $request->string('ip_address')->value();
        $customerId = $request->string('customer_id')->value();

        $rules = RiskRule::active()
            ->where('rule_type', RuleType::IpScore->value)
            ->where(function ($q) use ($ipAddress): void {
                $q->where('applies_to', 'global')
                  ->orWhere('subject_id', $ipAddress);
            })
            ->get();

        $totalScore    = 0.0;
        $triggeredRules = [];

        foreach ($rules as $rule) {
            $triggered = $rule->subject_id !== null
                ? ($ipAddress === $rule->subject_id)
                : ($rule->applies_to === 'global');

            if ($triggered) {
                $score          = (float) $rule->risk_score_weight * 20.0;
                $totalScore    += $score;
                $triggeredRules[] = [
                    'rule_id'   => $rule->id,
                    'rule_name' => $rule->name,
                    'score'     => $score,
                    'action'    => $rule->action->value,
                ];
            }
        }

        return response()->json([
            'ip_address'      => $ipAddress,
            'customer_id'     => $customerId,
            'risk_score'      => min(100.0, $totalScore),
            'triggered_rules' => $triggeredRules,
        ]);
    }

    public function evaluateBeneficiaryRisk(BeneficiaryRiskRequest $request): JsonResponse
    {
        $beneficiaryId   = $request->string('beneficiary_id')->value();
        $beneficiaryName = $request->string('beneficiary_name')->value();
        $beneficiaryType = $request->string('beneficiary_type')->value();
        $isNew           = (bool) $request->input('is_new_beneficiary');
        $dob             = $request->input('date_of_birth');

        // Screen beneficiary against watchlist
        $watchlistMatches = $this->watchlistScreener->screen(
            subjectType: $beneficiaryType,
            subjectId:   $beneficiaryId,
            name:        $beneficiaryName,
            dob:         $dob,
        );

        // New beneficiary baseline score increment
        $baseScore = $isNew ? 15.0 : 0.0;

        // Escalate score for watchlist hits
        $watchlistScore = count($watchlistMatches) > 0 ? 100.0 : 0.0;
        $totalScore     = min(100.0, $baseScore + $watchlistScore);

        return response()->json([
            'beneficiary_id'   => $beneficiaryId,
            'is_new_beneficiary' => $isNew,
            'watchlist_matches'  => count($watchlistMatches),
            'risk_score'         => $totalScore,
            'decision'           => $totalScore >= 85 ? 'block' : ($totalScore >= 50 ? 'review' : 'allow'),
        ]);
    }

    // ── T065: Allow transaction (manual clear) ────────────────────────────────

    public function allowTransaction(ManualDecisionRequest $request): ManualDecisionResource|JsonResponse
    {
        return $this->recordManualDecision($request, 'clear', 'risk.evaluation.allow');
    }

    // ── T066: Block transaction (manual block) ────────────────────────────────

    public function blockTransaction(ManualDecisionRequest $request): ManualDecisionResource|JsonResponse
    {
        return $this->recordManualDecision($request, 'block', 'risk.evaluation.block');
    }

    // ── T068: Send evaluation for manual review ────────────────────────────────

    public function sendTransactionForManualReview(SendForReviewRequest $request): RiskAlertResource|JsonResponse
    {
        $evaluationId = $request->string('evaluation_id')->value();
        $evaluation   = RiskEvaluation::find($evaluationId);

        if ($evaluation === null) {
            return response()->json(['message' => 'Risk evaluation not found.'], 404);
        }

        $actorId = (string) (auth()->id() ?? self::SYSTEM_UUID);

        try {
            $alert = $this->alertService->sendForManualReview($evaluation, $actorId);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return (new RiskAlertResource($alert))->response()->setStatusCode(201);
    }

    // ── Shared helper for allow/block ─────────────────────────────────────────

    private function recordManualDecision(ManualDecisionRequest $request, string $decision, string $auditAction): ManualDecisionResource|JsonResponse
    {
        $evaluationId = $request->string('evaluation_id')->value();
        $rationale    = $request->string('rationale')->value();

        $evaluation = RiskEvaluation::find($evaluationId);
        if ($evaluation === null) {
            return response()->json(['message' => 'Risk evaluation not found.'], 404);
        }

        // Find or create alert linked to this evaluation
        $alert = RiskAlert::where('correlation_id', $evaluation->idempotency_key)
            ->whereIn('status', [AlertStatus::Open->value, AlertStatus::UnderInvestigation->value])
            ->first();

        if ($alert === null) {
            $actorId = (string) (auth()->id() ?? self::SYSTEM_UUID);
            // Create a minimal alert to anchor the decision
            $alert = RiskAlert::create([
                'id'             => (string) Str::orderedUuid(),
                'alert_type'     => AlertType::ThresholdBreach->value,
                'status'         => AlertStatus::Open->value,
                'severity'       => ($evaluation->risk_level instanceof RiskLevel)
                                        ? $evaluation->risk_level->value
                                        : (string) $evaluation->risk_level,
                'subject_type'   => 'customer',
                'subject_id'     => $evaluation->customer_id,
                'title'          => mb_substr('Manual Decision: '.$evaluation->transaction_reference, 0, 255),
                'description'    => "Manual {$decision} applied to evaluation {$evaluationId}.",
                'correlation_id' => $evaluation->idempotency_key,
                'sla_due_at'     => now()->addHours(24),
                'created_by'     => $actorId,
                'updated_by'     => $actorId,
                'version'        => 1,
            ]);
        }

        // 409 if decision already recorded
        if (RiskDecision::where('alert_id', $alert->id)->exists()) {
            return response()->json(['message' => 'A decision already exists for this evaluation.'], 409);
        }

        $actorId = (string) (auth()->id() ?? self::SYSTEM_UUID);

        $riskDecision = RiskDecision::create([
            'id'         => (string) Str::orderedUuid(),
            'alert_id'   => $alert->id,
            'decision'   => $decision,
            'rationale'  => $rationale,
            'decided_by' => $actorId,
            'decided_at' => now(),
            'created_at' => now(),
        ]);

        // Attach evaluation_id to resource for response
        $riskDecision->evaluation_id = $evaluationId;

        $this->audit->record(new AuditEventData(
            action:        $auditAction,
            actorType:     'user',
            resourceType:  'risk_decision',
            correlationId: $evaluationId,
            outcome:       'success',
            metadata:      [
                'evaluation_id' => $evaluationId,
                'decision_id'   => $riskDecision->id,
                'decision'      => $decision,
            ],
        ));

        return new ManualDecisionResource($riskDecision);
    }

    // ── T084: Dispatch unusual pattern detection job ──────────────────────────

    public function detectUnusualTransactionPattern(PatternDetectionRequest $request): JsonResponse
    {
        $subjectType  = $request->string('subject_type')->value();
        $subjectId    = $request->string('subject_id')->value();
        $lookbackDays = (int) $request->input('lookback_days', 30);

        $job   = new DetectUnusualPatternJob($subjectType, $subjectId, $lookbackDays);
        $jobId = (string) Str::uuid();
        dispatch($job);

        return response()->json([
            'job_id'      => $jobId,
            'queued_at'   => now()->toIso8601String(),
            'subject_type' => $subjectType,
            'subject_id'  => $subjectId,
        ], 202);
    }

    // ── T085: Dispatch rapid fund movement detection job ──────────────────────

    public function detectRapidFundMovement(PatternDetectionRequest $request): JsonResponse
    {
        $subjectType = $request->string('subject_type')->value();
        $subjectId   = $request->string('subject_id')->value();

        $job   = new DetectRapidFundMovementJob($subjectType, $subjectId);
        $jobId = (string) Str::uuid();
        dispatch($job);

        return response()->json([
            'job_id'       => $jobId,
            'queued_at'    => now()->toIso8601String(),
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
        ], 202);
    }

    // ── T090: Auth failure detection ──────────────────────────────────────────

    public function detectRepeatedFailedAuthentication(AuthFailureRequest $request): JsonResponse
    {
        $subjectType = $request->string('subject_type')->value();
        $subjectId   = $request->string('subject_id')->value();
        $deviceId    = $request->string('device_id')->value();

        $failureCount = $this->ruleEngine->recordAuthFailure($subjectType, $subjectId, $deviceId);

        // Load the threshold from the active AuthFailureCount rule (default 5)
        $rule = RiskRule::active()
            ->where('rule_type', RuleType::AuthFailureCount->value)
            ->where(function ($q) use ($subjectId): void {
                $q->where('subject_id', $subjectId)->orWhere('applies_to', 'global');
            })
            ->orderByRaw("CASE WHEN subject_id = ? THEN 0 ELSE 1 END", [$subjectId])
            ->first();

        $threshold = $rule?->threshold_count ?? 5;

        $alertCreated = false;
        $alertId      = null;

        if ($failureCount >= $threshold) {
            $existingAlert = RiskAlert::where('subject_id', $subjectId)
                ->where('alert_type', 'auth_failure')
                ->whereIn('status', ['open', 'under_investigation'])
                ->first();

            if ($existingAlert === null) {
                $alert        = $this->alertService->createAuthFailureAlert($subjectType, $subjectId, $failureCount);
                $alertCreated = true;
                $alertId      = $alert->id;
            } else {
                $alertId = $existingAlert->id;
            }
        }

        return response()->json([
            'subject_type'  => $subjectType,
            'subject_id'    => $subjectId,
            'failure_count' => $failureCount,
            'threshold'     => $threshold,
            'alert_created' => $alertCreated,
            'alert_id'      => $alertId,
        ]);
    }
}
