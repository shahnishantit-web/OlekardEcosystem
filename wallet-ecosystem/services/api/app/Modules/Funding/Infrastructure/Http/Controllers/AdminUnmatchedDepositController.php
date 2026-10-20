<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Controllers;

use App\Modules\Funding\Application\Services\BankReconciliationService;
use App\Modules\Funding\Infrastructure\Models\UnmatchedDepositModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdminUnmatchedDepositController extends Controller
{
    public function __construct(
        private readonly BankReconciliationService $reconciliationService,
    ) {}

    /**
     * GET /api/v1/admin/funding/unmatched-deposits
     *
     * Lists unresolved deposits for admin review.
     * Requires `funding.unmatched.review` permission (or `funding.admin` for full access).
     */
    public function index(Request $request): JsonResponse
    {
        $deposits = UnmatchedDepositModel::where('resolution_status', 'unresolved')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $deposits->map(fn ($d) => [
                'id'                      => $d->id,
                'bank_reference_code'     => $d->bank_reference_code,
                'amount'                  => $d->amount,
                'currency_code'           => $d->currency_code,
                'bank_notification_id'    => $d->bank_notification_id,
                'funding_transaction_id'  => $d->funding_transaction_id,
                'resolution_status'       => $d->resolution_status,
                'created_at'              => $d->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'total'    => $deposits->total(),
                'page'     => $deposits->currentPage(),
                'per_page' => $deposits->perPage(),
                'pages'    => $deposits->lastPage(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/admin/funding/unmatched-deposits/{id}
     *
     * Manually resolve an unmatched deposit by linking it to a funding transaction
     * or marking it as rejected.
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();

        $request->validate([
            'resolution'            => ['required', 'in:matched,rejected'],
            'funding_transaction_id' => ['required_if:resolution,matched', 'nullable', 'uuid'],
            'resolution_notes'      => ['nullable', 'string', 'max:500'],
        ]);

        $deposit = $this->reconciliationService->resolveUnmatched([
            'unmatched_deposit_id'   => $id,
            'resolution'             => $request->input('resolution'),
            'funding_transaction_id' => $request->input('funding_transaction_id'),
            'resolution_notes'       => $request->input('resolution_notes'),
            'resolved_by'            => $request->user()?->id ?? $request->header('X-Test-User-Id'),
            'correlation_id'         => $correlationId,
        ]);

        return response()->json([
            'data' => [
                'id'               => $deposit->id,
                'resolution_status' => $deposit->resolution_status,
                'resolved_at'      => $deposit->resolved_at?->toIso8601String(),
            ],
        ]);
    }
}
