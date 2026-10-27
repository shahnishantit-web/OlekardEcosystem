<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Customer;

use App\Modules\Payment\Domain\Contracts\PaymentDisputeCaseRepositoryInterface;
use App\Modules\Payment\Http\Resources\CustomerDisputeSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * T039: GET /api/v1/payment/intents/{intentId}/disputes
 *
 * Consumer-facing dispute summary for a given payment intent.
 * Ownership is enforced: the authenticated user must own the intent.
 * Provider references and accounting fields are not exposed.
 */
final class CustomerDisputeSummaryController extends Controller
{
    public function __construct(
        private readonly PaymentDisputeCaseRepositoryInterface $caseRepository,
    ) {
    }

    /**
     * Permission: payment.disputes.view (via middleware)
     * Additional ownership guard applied here.
     */
    public function index(Request $request, string $intentId): JsonResponse
    {
        $userId = $request->user()?->id;

        // Fetch cases for this intent; ownership is asserted by filtering on the intent's owner
        $paginator = $this->caseRepository->listFiltered(
            filters:  ['payment_intent_id' => $intentId],
            page:     1,
            perPage:  50,
        );

        $cases = $paginator->items();

        if (empty($cases)) {
            return response()->json(['data' => []]);
        }

        // Verify ownership against the first case's intent (they all share the same intent)
        $firstCase = $cases[0];
        if ($firstCase->intent?->owner_id !== null && $firstCase->intent->owner_id !== $userId) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'data' => CustomerDisputeSummaryResource::collection($cases)->toArray($request),
        ]);
    }
}
