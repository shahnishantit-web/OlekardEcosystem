<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Ledger\Application\Services\LedgerJournalQueryService;
use App\Modules\Payment\Application\Jobs\QueryPaymentIntentStatusJob;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * P21 T004: Admin intent timeline drill-down.
 *
 * Permission: payment.intents.admin (enforced via route middleware)
 *
 * Provides intent metadata, all attempts in order, pending-accounting flag,
 * ledger journal summaries, and fulfilment status. Sensitive fields are stripped
 * before response — ledger data accessed via LedgerJournalQueryService (Ledger module).
 */
final class PaymentIntentTimelineAdminController extends Controller
{
    public function __construct(
        private readonly PaymentIntentRepositoryInterface $intentRepository,
        private readonly LedgerJournalQueryService        $ledgerJournalQueryService,
    ) {
    }

    /**
     * GET /api/v1/admin/payment/intents/{id}/timeline
     */
    public function show(string $id): JsonResponse
    {
        $intent = $this->intentRepository->findById($id);

        if ($intent === null) {
            return response()->json(['message' => 'Payment intent not found.'], 404);
        }

        $attempts = DB::connection('payment')
            ->table('payment.payment_attempts')
            ->where('payment_intent_id', $id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->values()
            ->map(function ($row, int $index): array {
                return [
                    'id'               => $row->id,
                    'attempt_number'   => $index + 1,
                    'status'           => $row->status,
                    'provider_code'    => $row->provider_code,
                    // Mask provider reference to last 4 chars — never expose full ref
                    'provider_reference' => $this->maskReference($row->provider_reference_id ?? null),
                    'submitted_at'     => $row->created_at,
                    'resolved_at'      => $row->confirmed_at ?? $row->failed_at ?? null,
                    'failure_reason'   => $row->failure_reason ?? null,
                ];
            })
            ->all();

        $pendingAccounting = DB::connection('payment')
            ->table('payment.payment_intents')
            ->where('id', $id)
            ->whereIn('accounting_status', ['pending_ledger', 'ledger_failed'])
            ->exists();

        $ledgerJournals = $this->ledgerJournalQueryService->forReference($id);

        $fulfilment = null;
        if ($intent->fulfilment_status !== null) {
            $fulfilment = [
                'status'       => $intent->fulfilment_status->value,
                'dispatched_at' => $intent->succeeded_at?->toIso8601String(),
                'completed_at'  => null,
            ];
        }

        return response()->json([
            'data' => [
                'intent'             => $this->formatIntent($intent),
                'attempts'           => $attempts,
                'fulfilment'         => $fulfilment,
                'ledger_journals'    => $ledgerJournals,
                'pending_accounting' => $pendingAccounting,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function formatIntent(PaymentIntentModel $intent): array
    {
        return [
            'id'                   => $intent->id,
            'status'               => $intent->status->value,
            'amount_minor'         => $intent->amount_minor,
            'currency_code'        => $intent->currency_code,
            'provider_code'        => null,
            'purpose_type'         => $intent->purpose_type,
            'organisation_id'      => $intent->organisation_id,
            'created_at'           => $intent->created_at?->toIso8601String() ?? '',
            'updated_at'           => $intent->updated_at?->toIso8601String() ?? '',
            'has_pending_fulfilment' => $intent->status->value === 'captured' && $intent->fulfilment_status === null,
        ];
    }

    /**
     * POST /api/v1/admin/payment/intents/{id}/query-status
     *
     * Dispatches a read-only provider status query job (202 async).
     * Permission: payment.intents.admin (enforced via route middleware)
     */
    public function queryStatus(string $id): JsonResponse
    {
        $intent = $this->intentRepository->findById($id);

        if ($intent === null) {
            return response()->json(['message' => 'Payment intent not found.'], 404);
        }

        QueryPaymentIntentStatusJob::dispatch($id);

        return response()->json(['message' => 'Status query dispatched.'], 202);
    }

    private function maskReference(?string $ref): ?string
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        $last4 = substr($ref, -4);

        return '****' . $last4;
    }
}
