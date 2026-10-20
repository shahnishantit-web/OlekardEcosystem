<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Controllers;

use App\Modules\AdminApp\Application\Services\AdminActionExecutorService;
use App\Modules\AdminApp\Application\Services\PendingAdminActionService;
use App\Modules\AdminApp\Infrastructure\Http\Resources\ErrorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ApprovalController
{
    public function __construct(
        private readonly PendingAdminActionService $pendingActions,
        private readonly AdminActionExecutorService $executor,
    ) {}

    public function approve(Request $request, string $approvalId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $checkerId     = (string) $request->user()?->id;

        $pendingAction = $this->pendingActions->findById($approvalId);

        if ($pendingAction === null) {
            return ErrorResource::make('ADMINAPP_APPROVAL_001', 'Pending action not found.', $correlationId, 404);
        }

        if (!$pendingAction->isPendingApproval()) {
            return ErrorResource::make('ADMINAPP_APPROVAL_002', 'Action is not in pending_approval state.', $correlationId, 422);
        }

        try {
            DB::transaction(function () use ($pendingAction, $checkerId, $correlationId): void {
                $this->pendingActions->approve($pendingAction->id, $checkerId, $correlationId);
                $pendingAction->refresh();
                $this->executor->execute($pendingAction, $checkerId, $correlationId);
            });
        } catch (\DomainException $e) {
            $code = $e->getMessage();
            if ($code === 'MAKER_CANNOT_APPROVE_OWN_ACTION') {
                return ErrorResource::make('ADMINAPP_APPROVAL_003', 'You cannot approve your own pending action.', $correlationId, 422);
            }
            return ErrorResource::make('ADMINAPP_APPROVAL_004', $e->getMessage(), $correlationId, 422);
        }

        return response()->json([
            'data' => [
                'approval_id' => $approvalId,
                'status'      => 'approved',
                'resolved_at' => now()->toIso8601ZuluString(),
            ],
        ]);
    }

    public function reject(Request $request, string $approvalId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $checkerId     = (string) $request->user()?->id;
        $reason        = $request->input('reason', '');

        $pendingAction = $this->pendingActions->findById($approvalId);

        if ($pendingAction === null) {
            return ErrorResource::make('ADMINAPP_APPROVAL_001', 'Pending action not found.', $correlationId, 404);
        }

        if (!$pendingAction->isPendingApproval()) {
            return ErrorResource::make('ADMINAPP_APPROVAL_002', 'Action is not in pending_approval state.', $correlationId, 422);
        }

        try {
            $this->pendingActions->reject($pendingAction->id, $checkerId, $reason, $correlationId);
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'MAKER_CANNOT_REJECT_OWN_ACTION') {
                return ErrorResource::make('ADMINAPP_APPROVAL_003', 'You cannot reject your own pending action.', $correlationId, 422);
            }
            return ErrorResource::make('ADMINAPP_APPROVAL_004', $e->getMessage(), $correlationId, 422);
        }

        return response()->json([
            'data' => [
                'approval_id' => $approvalId,
                'status'      => 'rejected',
                'reason'      => $reason,
                'resolved_at' => now()->toIso8601ZuluString(),
            ],
        ]);
    }
}
