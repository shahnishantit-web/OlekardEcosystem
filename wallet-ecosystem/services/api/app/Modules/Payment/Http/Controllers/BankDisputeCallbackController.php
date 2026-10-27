<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * T022: POST /api/v1/payment/bank/dispute-callback
 *
 * Authenticated provider endpoint (identity.auth middleware).
 * Delegates deduplication and case creation to DisputeService::receive().
 */
final class BankDisputeCallbackController extends Controller
{
    public function __construct(
        private readonly DisputeService $disputeService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_code'              => ['required', 'string', 'max:64'],
            'provider_dispute_reference' => ['required', 'string', 'max:255'],
            'payment_attempt_id'         => ['required', 'uuid'],
            'case_type'                  => ['required', 'string', 'in:reversal,dispute,chargeback'],
            'disputed_amount_minor'      => ['required', 'integer', 'min:1'],
            'currency_code'              => ['required', 'string', 'size:3'],
            'reason_code'                => ['nullable', 'string', 'max:64'],
            'response_deadline'          => ['nullable', 'date'],
            'correlation_id'             => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $result = $this->disputeService->receive($validated);

            return response()->json($result, Response::HTTP_CREATED);
        } catch (\InvalidArgumentException) {
            return response()->json([
                'message' => 'Payment attempt not found. ReconciliationException recorded.',
                'error'   => 'unmatched_attempt',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
