<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Http\Controllers;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Transfer\Domain\Enums\TransferFailureReason;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Domain\Events\TransferFailed;
use App\Modules\Transfer\Infrastructure\Http\Resources\TransferResource;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

final class AdminTransferController extends Controller
{
    public function __construct(
        private readonly AuditServiceInterface   $audit,
        private readonly EventPublisherInterface $events,
    ) {}

    /** GET /api/v1/admin/transfers */
    public function index(Request $request): JsonResponse
    {
        $query = TransferTransactionModel::query();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('sender_customer_id')) {
            $query->where('sender_customer_id', $request->input('sender_customer_id'));
        }

        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->input('to_date'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $results = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => TransferResource::collection($results->items()),
            'meta' => [
                'current_page' => $results->currentPage(),
                'per_page'     => $results->perPage(),
                'total'        => $results->total(),
                'last_page'    => $results->lastPage(),
            ],
        ]);
    }

    /** GET /api/v1/admin/transfers/{transferId} */
    public function show(string $transferId): JsonResponse
    {
        $tx = TransferTransactionModel::find($transferId);

        if ($tx === null) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Transfer not found.'],
            ], 404);
        }

        return response()->json(['data' => new TransferResource($tx)]);
    }

    /** POST /api/v1/admin/transfers/{transferId}/force-fail */
    public function forceFail(Request $request, string $transferId): JsonResponse
    {
        $tx = TransferTransactionModel::find($transferId);

        if ($tx === null) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Transfer not found.'],
            ], 404);
        }

        if ($tx->status->isTerminal()) {
            return response()->json([
                'error' => ['code' => 'TRANSFER_ALREADY_TERMINAL', 'message' => 'Transfer is already in a terminal state.'],
            ], 422);
        }

        $adminId       = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $correlationId = $request->header('X-Correlation-Id', (string) Uuid::uuid7());
        $reason        = $request->input('reason', 'Admin force-failed');

        $tx->status         = TransferStatus::Failed;
        $tx->failure_reason = TransferFailureReason::SystemError;
        $tx->save();

        $this->events->publish(new TransferFailed(
            transferId:     $tx->id,
            senderWalletId: $tx->sender_wallet_id,
            currencyCode:   $tx->currency_code,
            grossAmount:    $tx->gross_amount,
            failureReason:  TransferFailureReason::SystemError->value,
            occurredAt:     now()->toIso8601String(),
            correlationId:  $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'transfer.admin.force_failed',
            actorId:       $adminId,
            actorType:     'ADMIN',
            resourceType:  'transfer_transaction',
            resourceId:    $transferId,
            correlationId: $correlationId,
            outcome:       'success',
            metadata:      ['reason' => $reason],
        ));

        return response()->json(['data' => new TransferResource($tx->fresh())]);
    }
}
