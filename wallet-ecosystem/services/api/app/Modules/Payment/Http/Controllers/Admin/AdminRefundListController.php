<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Jobs\QueryRefundStatusJob;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Http\Resources\AdminRefundDetailResource;
use App\Modules\Payment\Http\Resources\AdminRefundListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * T034: US7 — Admin refund management (list, detail, query-status).
 *
 * All permission checks are enforced via route middleware:
 *   - payment.refunds.view  → list + show
 *   - payment.refunds.manage → queryStatus
 *
 * No state mutation is performed on list/show endpoints.
 */
final class AdminRefundListController extends Controller
{
    public function __construct(
        private readonly PaymentRefundRepositoryInterface $refundRepository,
    ) {
    }

    /**
     * GET /v1/admin/payment/refunds
     * Permission: payment.refunds.view
     */
    public function index(Request $request): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $filters = array_filter([
            'status'               => $request->query('status'),
            'payment_intent_id'    => $request->query('payment_intent_id'),
            'payment_attempt_id'   => $request->query('payment_attempt_id'),
            'provider_submitted_ref' => $request->query('provider_submitted_ref'),
            'requested_from'       => $request->query('requested_from'),
            'requested_to'         => $request->query('requested_to'),
        ], static fn($v) => $v !== null && $v !== '');

        $paginator = $this->refundRepository->listFiltered($filters, $page, $perPage);

        return response()->json([
            'data' => AdminRefundListResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /v1/admin/payment/refunds/{id}
     * Permission: payment.refunds.view
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $refund = $this->refundRepository->findById($id);

        if ($refund === null) {
            return response()->json(['message' => 'Refund not found.'], 404);
        }

        return response()->json([
            'data' => (new AdminRefundDetailResource($refund))->toArray($request),
        ]);
    }

    /**
     * POST /v1/admin/payment/refunds/{id}/query-status
     * Permission: payment.refunds.manage
     *
     * Dispatches QueryRefundStatusJob immediately. Returns 422 if refund is terminal.
     */
    public function queryStatus(string $id): JsonResponse
    {
        $refund = $this->refundRepository->findById($id);

        if ($refund === null) {
            return response()->json(['message' => 'Refund not found.'], 404);
        }

        if ($refund->status->isTerminal()) {
            return response()->json([
                'message' => 'Refund is in a terminal state; status query is not applicable.',
                'code'    => 'REFUND_TERMINAL',
            ], 422);
        }

        QueryRefundStatusJob::dispatch($refund->id);

        return response()->json(['message' => 'Status query dispatched.'], 202);
    }
}
