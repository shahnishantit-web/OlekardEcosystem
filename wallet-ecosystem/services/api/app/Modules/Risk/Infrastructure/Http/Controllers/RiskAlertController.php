<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Controllers;

use App\Modules\Risk\Application\Commands\CreateRiskAlertCommand;
use App\Modules\Risk\Application\Commands\FreezeAccountCommand;
use App\Modules\Risk\Application\Commands\InvestigateAlertCommand;
use App\Modules\Risk\Application\Commands\ResolveAlertCommand;
use App\Modules\Risk\Application\Services\AccountHoldService;
use App\Modules\Risk\Application\Services\AlertService;
use App\Modules\Risk\Domain\Models\AccountHold;
use App\Modules\Risk\Domain\Models\RiskAlert;
use App\Modules\Risk\Infrastructure\Http\Requests\CreateAlertRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\FreezeAccountRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\InvestigateAlertRequest;
use App\Modules\Risk\Infrastructure\Http\Requests\ResolveAlertRequest;
use App\Modules\Risk\Infrastructure\Http\Resources\AccountHoldResource;
use App\Modules\Risk\Infrastructure\Http\Resources\AlertDetailResource;
use App\Modules\Risk\Infrastructure\Http\Resources\RiskAlertResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class RiskAlertController extends Controller
{
    public function __construct(
        private readonly AlertService      $alertService,
        private readonly AccountHoldService $holdService,
    ) {}

    // ── T095: Create alert ────────────────────────────────────────────────────

    public function createRiskAlert(CreateAlertRequest $request): AlertDetailResource|JsonResponse
    {
        $actorId = (string) (auth()->id() ?? '00000000-0000-0000-0000-000000000000');

        $cmd = new CreateRiskAlertCommand(
            alertType:     $request->string('alert_type')->value(),
            severity:      $request->string('severity')->value(),
            subjectType:   $request->string('subject_type')->value(),
            subjectId:     $request->string('subject_id')->value(),
            title:         $request->string('title')->value(),
            description:   $request->string('description')->value(),
            actorId:       $actorId,
            correlationId: $request->input('correlation_id'),
            assignedTo:    $request->input('assigned_to'),
            eventIds:      $request->input('event_ids', []),
        );

        try {
            $alert = $this->alertService->createAlert($cmd);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return (new AlertDetailResource($alert))->response()->setStatusCode(201);
    }

    // ── T096: List alerts ─────────────────────────────────────────────────────

    public function listAlerts(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = RiskAlert::query()->latest();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }

        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->input('assigned_to'));
        }

        return RiskAlertResource::collection($query->paginate($perPage));
    }

    // ── T097: Get alert ───────────────────────────────────────────────────────

    public function getAlert(Request $request, string $id): AlertDetailResource|JsonResponse
    {
        $alert = RiskAlert::with('decisions')->find($id);

        if ($alert === null) {
            return response()->json(['message' => 'Alert not found.'], 404);
        }

        return new AlertDetailResource($alert);
    }

    // ── T099: Investigate alert ───────────────────────────────────────────────

    public function investigateRiskAlert(InvestigateAlertRequest $request): AlertDetailResource|JsonResponse
    {
        $actorId = (string) (auth()->id() ?? '00000000-0000-0000-0000-000000000000');

        $cmd = new InvestigateAlertCommand(
            alertId:    $request->string('alert_id')->value(),
            assignedTo: $request->string('assigned_to')->value(),
            version:    (int) $request->input('version'),
            actorId:    $actorId,
        );

        try {
            $alert = $this->alertService->investigateAlert($cmd);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return new AlertDetailResource($alert);
    }

    // ── T101: Resolve alert ───────────────────────────────────────────────────

    public function resolveRiskAlert(ResolveAlertRequest $request): AlertDetailResource|JsonResponse
    {
        $actorId = (string) (auth()->id() ?? '00000000-0000-0000-0000-000000000000');

        $cmd = new ResolveAlertCommand(
            alertId:                  $request->string('alert_id')->value(),
            decision:                 $request->string('decision')->value(),
            rationale:                $request->string('rationale')->value(),
            version:                  (int) $request->input('version'),
            decidedBy:                $actorId,
            checkerId:                $request->input('checker_id'),
            sarNarrative:             $request->input('sar_narrative'),
            sarSuspectedOffences:     $request->input('sar_suspected_offences'),
            sarTransactionReferences: $request->input('sar_transaction_references'),
            sarAmountInvolved:        $request->input('sar_amount_involved') !== null
                                          ? (string) $request->input('sar_amount_involved')
                                          : null,
            sarCurrency:              $request->input('sar_currency'),
            sarPeriodStart:           $request->input('sar_period_start'),
            sarPeriodEnd:             $request->input('sar_period_end'),
        );

        try {
            $alert = $this->alertService->resolveAlert($cmd);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return new AlertDetailResource($alert);
    }

    // ── T107: Freeze account ──────────────────────────────────────────────────

    public function freezeAccountDueToRisk(FreezeAccountRequest $request): AccountHoldResource|JsonResponse
    {
        $actorId = (string) (auth()->id() ?? '00000000-0000-0000-0000-000000000000');

        $cmd = new FreezeAccountCommand(
            subjectType: $request->string('subject_type')->value(),
            subjectId:   $request->string('subject_id')->value(),
            reason:      $request->string('reason')->value(),
            placedBy:    $actorId,
            checkerId:   $request->string('checker_id')->value(),
            walletId:    $request->input('wallet_id'),
            alertId:     $request->input('alert_id'),
            decisionId:  $request->input('decision_id'),
            expiresAt:   $request->input('expires_at'),
        );

        try {
            $hold = $this->holdService->placeHold($cmd);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return (new AccountHoldResource($hold))->response()->setStatusCode(201);
    }

    // ── Lift account hold (T123) ──────────────────────────────────────────────

    public function liftAccountHold(Request $request): AccountHoldResource|JsonResponse
    {
        $request->validate([
            'hold_id'     => ['required', 'uuid'],
            'lift_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $actorId = (string) (auth()->id() ?? '00000000-0000-0000-0000-000000000000');

        try {
            $hold = $this->holdService->liftHold(
                $request->input('hold_id'),
                $actorId,
                $request->input('lift_reason'),
            );
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return new AccountHoldResource($hold);
    }
}
