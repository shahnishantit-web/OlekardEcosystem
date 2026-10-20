<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Controllers;

use App\Modules\Risk\Application\Commands\ConfigureRiskRuleCommand;
use App\Modules\Risk\Application\Services\RuleEngineService;
use App\Modules\Risk\Domain\Enums\RuleType;
use App\Modules\Risk\Domain\Models\RiskRule;
use App\Modules\Risk\Infrastructure\Http\Requests\AmountLimitRuleRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\SubjectLimitsRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\VelocityAmountRuleRequest;
use App\Modules\Risk\Infrastructure\Http\Resources\RiskRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class RiskRuleController extends Controller
{
    public function __construct(
        private readonly RuleEngineService $ruleEngine,
    ) {}

    // ── T079: List rules ──────────────────────────────────────────────────────

    public function listRules(Request $request): AnonymousResourceCollection
    {
        $query = RiskRule::query()->where('is_active', true);

        if ($request->has('rule_type')) {
            $query->where('rule_type', $request->input('rule_type'));
        }

        if ($request->has('applies_to')) {
            $query->where('applies_to', $request->input('applies_to'));
        }

        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }

        $rules = $query->orderBy('created_at', 'desc')->paginate(25);

        return RiskRuleResource::collection($rules);
    }

    // ── T073: Per-transaction amount limit ────────────────────────────────────

    public function configurePerTransactionLimit(AmountLimitRuleRequest $request): RiskRuleResource|JsonResponse
    {
        $actorId = (string) (auth()->id() ?? '00000000-0000-0000-0000-000000000000');

        $cmd = new ConfigureRiskRuleCommand(
            ruleType:        RuleType::AmountLimit,
            appliesToType:   $request->string('applies_to')->value(),
            appliesToId:     $request->input('subject_id'),
            thresholdAmount: $request->string('threshold_amount')->value(),
            action:          $request->string('action')->value(),
            riskScoreWeight: (float) $request->input('risk_score_weight', 1.0),
            effectiveFrom:   $request->input('effective_from'),
            effectiveUntil:  $request->input('effective_until'),
            description:     $request->input('description'),
            actorId:         $actorId,
        );

        $isUpdate = RiskRule::where('name', $cmd->deriveName())
            ->where(function ($q) use ($cmd): void {
                if ($cmd->appliesToId !== null) {
                    $q->where('subject_id', $cmd->appliesToId);
                } else {
                    $q->whereNull('subject_id');
                }
            })
            ->where('is_active', true)
            ->exists();

        $rule = $this->ruleEngine->configureRule($cmd);
        $statusCode = $isUpdate ? 200 : 201;

        return (new RiskRuleResource($rule))->response()->setStatusCode($statusCode);
    }

    // ── T074: Daily velocity limit ────────────────────────────────────────────

    public function configureDailyLimit(VelocityAmountRuleRequest $request): RiskRuleResource|JsonResponse
    {
        return $this->configureVelocityRule($request, 86400);
    }

    // ── T075: Monthly velocity limit ──────────────────────────────────────────

    public function configureMonthlyLimit(VelocityAmountRuleRequest $request): RiskRuleResource|JsonResponse
    {
        return $this->configureVelocityRule($request, 2592000);
    }

    // ── T076: Customer subject limits ─────────────────────────────────────────

    public function configureCustomerLimits(SubjectLimitsRequest $request): JsonResponse
    {
        return $this->configureSubjectLimits($request, 'customer');
    }

    // ── T077: Agent subject limits ────────────────────────────────────────────

    public function configureAgentLimits(SubjectLimitsRequest $request): JsonResponse
    {
        return $this->configureSubjectLimits($request, 'agent');
    }

    // ── T078: Wallet subject limits ───────────────────────────────────────────

    public function configureWalletLimits(SubjectLimitsRequest $request): JsonResponse
    {
        return $this->configureSubjectLimits($request, 'wallet');
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function configureVelocityRule(VelocityAmountRuleRequest $request, int $defaultWindowSeconds): RiskRuleResource|JsonResponse
    {
        $actorId = (string) (auth()->id() ?? '00000000-0000-0000-0000-000000000000');
        $window  = (int) $request->input('window_seconds', $defaultWindowSeconds);

        $cmd = new ConfigureRiskRuleCommand(
            ruleType:        RuleType::VelocityAmount,
            appliesToType:   $request->string('applies_to')->value(),
            appliesToId:     $request->input('subject_id'),
            thresholdAmount: $request->string('threshold_amount')->value(),
            windowSeconds:   $window,
            action:          $request->string('action')->value(),
            riskScoreWeight: (float) $request->input('risk_score_weight', 1.0),
            effectiveFrom:   $request->input('effective_from'),
            effectiveUntil:  $request->input('effective_until'),
            description:     $request->input('description'),
            actorId:         $actorId,
        );

        $isUpdate = RiskRule::where('name', $cmd->deriveName())
            ->where(function ($q) use ($cmd): void {
                if ($cmd->appliesToId !== null) {
                    $q->where('subject_id', $cmd->appliesToId);
                } else {
                    $q->whereNull('subject_id');
                }
            })
            ->where('is_active', true)
            ->exists();

        $rule = $this->ruleEngine->configureRule($cmd);
        $statusCode = $isUpdate ? 200 : 201;

        return (new RiskRuleResource($rule))->response()->setStatusCode($statusCode);
    }

    private function configureSubjectLimits(SubjectLimitsRequest $request, string $subjectType): JsonResponse
    {
        $actorId   = (string) (auth()->id() ?? '00000000-0000-0000-0000-000000000000');
        $subjectId = $request->string('subject_id')->value();
        $action    = $request->string('action')->value();
        $configured = [];

        if ($request->has('per_transaction_limit')) {
            $cmd = new ConfigureRiskRuleCommand(
                ruleType:        RuleType::AmountLimit,
                appliesToType:   $subjectType,
                appliesToId:     $subjectId,
                thresholdAmount: $request->string('per_transaction_limit')->value(),
                action:          $action,
                effectiveFrom:   $request->input('effective_from'),
                effectiveUntil:  $request->input('effective_until'),
                actorId:         $actorId,
            );
            $configured[] = new RiskRuleResource($this->ruleEngine->configureRule($cmd));
        }

        if ($request->has('daily_limit')) {
            $cmd = new ConfigureRiskRuleCommand(
                ruleType:        RuleType::VelocityAmount,
                appliesToType:   $subjectType,
                appliesToId:     $subjectId,
                thresholdAmount: $request->string('daily_limit')->value(),
                windowSeconds:   86400,
                action:          $action,
                effectiveFrom:   $request->input('effective_from'),
                effectiveUntil:  $request->input('effective_until'),
                actorId:         $actorId,
            );
            $configured[] = new RiskRuleResource($this->ruleEngine->configureRule($cmd));
        }

        if ($request->has('monthly_limit')) {
            $cmd = new ConfigureRiskRuleCommand(
                ruleType:        RuleType::VelocityAmount,
                appliesToType:   $subjectType,
                appliesToId:     $subjectId,
                thresholdAmount: $request->string('monthly_limit')->value(),
                windowSeconds:   2592000,
                action:          $action,
                effectiveFrom:   $request->input('effective_from'),
                effectiveUntil:  $request->input('effective_until'),
                actorId:         $actorId,
            );
            $configured[] = new RiskRuleResource($this->ruleEngine->configureRule($cmd));
        }

        return response()->json([
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'rules'        => array_map(fn ($r) => $r->toArray(request()), $configured),
        ]);
    }
}
