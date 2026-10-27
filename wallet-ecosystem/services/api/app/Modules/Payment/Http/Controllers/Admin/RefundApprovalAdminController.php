<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\Exceptions\SelfApprovalNotAllowedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

/**
 * Admin controller for refund approval gate (US4).
 * All permission enforcement is via route middleware — no $this->authorize() calls.
 */
final class RefundApprovalAdminController extends Controller
{
    public function __construct(
        private readonly RefundService $refundService,
        private readonly PaymentRefundRepositoryInterface $refundRepository,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $paginator = $this->refundRepository->listByStatus(
            PaymentRefundStatus::PendingApproval,
            $page,
            $perPage,
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $validated     = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $actorId       = $request->user()?->id ?? 'system';
        $correlationId = (string) Uuid::uuid7();

        try {
            $refund = $this->refundService->approveRefund($id, $actorId, $correlationId);
        } catch (SelfApprovalNotAllowedException) {
            return response()->json([
                'error' => ['code' => 'SELF_APPROVAL_NOT_ALLOWED', 'message' => 'The requester of a refund cannot also approve it.'],
            ], 403);
        } catch (InvalidStateTransitionException $e) {
            return response()->json([
                'error' => ['code' => 'REFUND_NOT_PENDING_APPROVAL', 'message' => $e->getMessage()],
            ], 409);
        }

        return response()->json(['data' => $this->safeRefundShape($refund)]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $validated     = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $actorId       = $request->user()?->id ?? 'system';
        $correlationId = (string) Uuid::uuid7();

        try {
            $refund = $this->refundService->rejectRefund($id, $actorId, $validated['reason'], $correlationId);
        } catch (SelfApprovalNotAllowedException) {
            return response()->json([
                'error' => ['code' => 'SELF_APPROVAL_NOT_ALLOWED', 'message' => 'The requester of a refund cannot also reject it.'],
            ], 403);
        } catch (InvalidStateTransitionException $e) {
            return response()->json([
                'error' => ['code' => 'REFUND_NOT_PENDING_APPROVAL', 'message' => $e->getMessage()],
            ], 409);
        }

        return response()->json(['data' => $this->safeRefundShape($refund)]);
    }

    /** @return array<string, mixed> */
    private function safeRefundShape(\App\Modules\Payment\Infrastructure\Models\PaymentRefundModel $refund): array
    {
        return [
            'id'                   => $refund->id,
            'payment_intent_id'    => $refund->payment_intent_id,
            'amount_minor'         => $refund->amount_minor,
            'status'               => $refund->status->value,
            'approval_required'    => $refund->approval_required,
            'requested_by'         => $refund->requested_by,
            'approved_by_actor_id' => $refund->approved_by_actor_id,
            'approved_at'          => $refund->approved_at?->toIso8601String(),
            'rejected_by_actor_id' => $refund->rejected_by_actor_id,
            'rejected_at'          => $refund->rejected_at?->toIso8601String(),
            'rejection_reason'     => $refund->rejection_reason,
            'reason'               => $refund->reason,
            'requested_at'         => $refund->requested_at?->toIso8601String(),
        ];
    }
}
