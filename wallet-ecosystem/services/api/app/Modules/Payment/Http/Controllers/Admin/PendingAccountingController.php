<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * P08 US4: Admin visibility into payment intents with unresolved accounting.
 * Exposes pending_ledger and ledger_failed intents for operator monitoring.
 */
final class PendingAccountingController extends Controller
{
    public function __construct(
        private readonly PaymentIntentRepositoryInterface $intentRepository,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $limit        = min(200, max(1, (int) $request->query('limit', 100)));
        $statusFilter = $request->query('accounting_status');

        $intents = $this->intentRepository->findAllUnresolvedAccounting(
            statusFilter: is_string($statusFilter) ? $statusFilter : null,
            limit:        $limit,
        );

        $data = $intents->map(static function ($intent): array {
            return [
                'id'                          => $intent->id,
                'status'                      => $intent->status->value,
                'accounting_status'           => $intent->accounting_status?->value,
                'organisation_id'             => $intent->organisation_id,
                'currency_code'               => $intent->currency_code,
                'amount_minor'                => $intent->amount_minor,
                'succeeded_at'                => $intent->succeeded_at?->toIso8601String(),
                'ledger_repair_at'            => $intent->ledger_repair_at?->toIso8601String(),
                'accounting_repair_attempts'  => $intent->accounting_repair_attempts,
                'seconds_since_capture'       => $intent->succeeded_at !== null
                    ? (int) $intent->succeeded_at->diffInSeconds(now())
                    : null,
            ];
        })->values()->all();

        return response()->json([
            'data'  => $data,
            'meta'  => [
                'count'          => count($data),
                'limit'          => $limit,
                'status_filter'  => $statusFilter ?? 'all',
            ],
        ]);
    }
}
