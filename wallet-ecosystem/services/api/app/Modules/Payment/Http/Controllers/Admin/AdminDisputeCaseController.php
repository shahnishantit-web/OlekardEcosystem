<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\DisputeService;
use App\Modules\Payment\Domain\Contracts\PaymentDisputeCaseRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentDisputeCaseStatus;
use App\Modules\Payment\Domain\Exceptions\DisputeCaseClosedException;
use App\Modules\Payment\Domain\Exceptions\DisputeCaseNotFoundException;
use App\Modules\Payment\Domain\Exceptions\DisputeInvalidTransitionException;
use App\Modules\Payment\Domain\ValueObjects\DisputeOutcomeRequest;
use App\Modules\Payment\Http\Resources\DisputeCaseDetailResource;
use App\Modules\Payment\Http\Resources\DisputeCaseListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * T027 + T032 + T036 + T037: Admin dispute case management.
 *
 * Route middleware enforces permissions:
 *   - payment.disputes.view         → index, show
 *   - payment.disputes.manage       → hold, outcome, submitEvidence (+ idempotency:require on hold/outcome)
 *   - payment.disputes.finance_review → acknowledgeFinanceReview
 */
final class AdminDisputeCaseController extends Controller
{
    public function __construct(
        private readonly PaymentDisputeCaseRepositoryInterface $caseRepository,
        private readonly DisputeService $disputeService,
    ) {
    }

    /**
     * T036 — GET /api/v1/admin/payment/dispute-cases
     * Permission: payment.disputes.view
     */
    public function index(Request $request): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $filters = array_filter([
            'status'                  => $request->query('status'),
            'case_type'               => $request->query('case_type'),
            'requires_finance_review' => $request->query('requires_finance_review') !== null
                ? filter_var($request->query('requires_finance_review'), FILTER_VALIDATE_BOOLEAN)
                : null,
            'deadline_before'         => $request->query('deadline_before'),
            'payment_intent_id'       => $request->query('payment_intent_id'),
            'payment_attempt_id'      => $request->query('payment_attempt_id'),
        ], static fn ($v) => $v !== null && $v !== '');

        $paginator = $this->caseRepository->listFiltered($filters, $page, $perPage);

        return response()->json([
            'data' => DisputeCaseListResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * T036 — GET /api/v1/admin/payment/dispute-cases/{id}
     * Permission: payment.disputes.view
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $case = $this->caseRepository->findById($id);

        if ($case === null) {
            return response()->json(['message' => 'Dispute case not found.'], 404);
        }

        $case->load(['events', 'hold']);

        return response()->json(['data' => (new DisputeCaseDetailResource($case))->toArray($request)]);
    }

    /**
     * T027 — POST /api/v1/admin/payment/dispute-cases/{id}/hold
     * Permission: payment.disputes.manage + idempotency:require
     *
     * Authorises a financial hold for the dispute case.
     * A1 fix: hold_amount_minor min 1 for exposure_type=hold; min 0 for settled_funds_receivable.
     */
    public function hold(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'hold_amount_minor' => ['required', 'integer', 'min:0'],
            'exposure_type'     => ['required', 'string', 'in:hold,settled_funds_receivable'],
            'authorised_by'     => ['required', 'string', 'max:255'],
            'correlation_id'    => ['nullable', 'string', 'max:128'],
        ]);

        // A1 fix: enforce min:1 only for standard hold type
        if ($validated['exposure_type'] === 'hold' && $validated['hold_amount_minor'] < 1) {
            return response()->json([
                'message' => 'hold_amount_minor must be at least 1 for exposure_type=hold.',
                'errors'  => ['hold_amount_minor' => ['Minimum 1 unit required for hold type.']],
            ], 422);
        }

        try {
            $case = $this->disputeService->authoriseHold(
                caseId:          $id,
                holdAmountMinor: $validated['hold_amount_minor'],
                exposureType:    $validated['exposure_type'],
                actorId:         $validated['authorised_by'],
                correlationId:   $validated['correlation_id'] ?? "dispute:hold:{$id}",
            );
        } catch (DisputeCaseNotFoundException) {
            return response()->json(['message' => 'Dispute case not found.'], 404);
        } catch (DisputeInvalidTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_TRANSITION'], 409);
        }

        return response()->json([
            'message' => 'Hold authorised.',
            'data'    => ['case_id' => $case->id, 'status' => $case->status->value],
        ]);
    }

    /**
     * T032 — POST /api/v1/admin/payment/dispute-cases/{id}/outcome
     * Permission: payment.disputes.manage + idempotency:require
     *
     * Records the provider's final outcome (won/lost/closed_reversed).
     * Idempotency key must be supplied by caller in X-Idempotency-Key header.
     */
    public function outcome(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'outcome'                    => ['required', 'string', 'in:won,lost,closed_reversed'],
            'provider_decision_reference' => ['nullable', 'string', 'max:255'],
            'chargeback_fee_minor'       => ['nullable', 'integer', 'min:0'],
            'actor_id'                   => ['nullable', 'string', 'max:255'],
        ]);

        $outcomeVO = new DisputeOutcomeRequest(
            outcome:                   PaymentDisputeCaseStatus::from($validated['outcome']),
            providerDecisionReference: $validated['provider_decision_reference'] ?? null,
            chargebackFeeMinor:        $validated['chargeback_fee_minor'] ?? 0,
        );

        try {
            $case = $this->disputeService->recordOutcome(
                caseId:  $id,
                request: $outcomeVO,
                actorId: $validated['actor_id'] ?? 'system',
            );
        } catch (DisputeCaseNotFoundException) {
            return response()->json(['message' => 'Dispute case not found.'], 404);
        } catch (DisputeInvalidTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_TRANSITION'], 409);
        }

        return response()->json([
            'message' => 'Outcome recorded.',
            'data'    => ['case_id' => $case->id, 'status' => $case->status->value],
        ]);
    }

    /**
     * T037 — POST /api/v1/admin/payment/dispute-cases/{id}/evidence
     * Permission: payment.disputes.manage
     */
    public function submitEvidence(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'evidence_type' => ['required', 'string', 'max:64'],
            'reference'     => ['required', 'string', 'max:255'],
            'notes'         => ['nullable', 'string', 'max:1024'],
            'actor_id'      => ['nullable', 'string', 'max:255'],
        ]);

        $evidenceRef   = $validated['reference'];
        $actorId       = $validated['actor_id'] ?? 'system';
        $correlationId = "dispute:evidence:{$id}:" . now()->getTimestamp();

        try {
            $this->disputeService->recordEvidence(
                caseId:            $id,
                evidenceReference: $evidenceRef,
                actorId:           $actorId,
                correlationId:     $correlationId,
            );
        } catch (DisputeCaseNotFoundException) {
            return response()->json(['message' => 'Dispute case not found.'], 404);
        } catch (DisputeCaseClosedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CASE_CLOSED'], 409);
        }

        return response()->json(['message' => 'Evidence recorded.'], 201);
    }

    /**
     * T037 — POST /api/v1/admin/payment/dispute-cases/{id}/finance-review
     * Permission: payment.disputes.finance_review
     *
     * Acknowledges the PostDrawLiability finance review gate.
     */
    public function acknowledgeFinanceReview(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'actor_id' => ['required', 'string', 'max:255'],
            'notes'    => ['nullable', 'string', 'max:1024'],
        ]);

        try {
            $this->disputeService->acknowledgeFinanceReview(
                caseId:  $id,
                actorId: $validated['actor_id'],
            );
        } catch (DisputeCaseNotFoundException) {
            return response()->json(['message' => 'Dispute case not found.'], 404);
        } catch (DisputeCaseClosedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CASE_CLOSED'], 409);
        }

        return response()->json(['message' => 'Finance review acknowledged.']);
    }
}
