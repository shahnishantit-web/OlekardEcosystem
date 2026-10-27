<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\PayoutReservationService;
use App\Modules\Payment\Domain\Exceptions\InsufficientPayableBalanceException;
use App\Modules\Payment\Domain\Exceptions\PayoutReservationExpiredException;
use App\Modules\Payment\Domain\Exceptions\PayoutSelfApprovalException;
use App\Modules\Payment\Http\Resources\PayoutReservationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

final class PayoutReservationAdminController extends Controller
{
    public function __construct(
        private readonly PayoutReservationService $service,
    ) {}

    public function createReservation(Request $request, string $payoutId): JsonResponse
    {
        Validator::make($request->all(), [
            'reserved_amount_minor' => ['required', 'integer', 'min:1'],
        ])->validate();

        $makerId = (string) $request->user()?->id;

        try {
            $reservation = $this->service->createReservation(
                $payoutId,
                (int) $request->input('reserved_amount_minor'),
                $makerId,
            );
        } catch (InsufficientPayableBalanceException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'INSUFFICIENT_BALANCE', 'message' => $e->getMessage()],
            ], 400);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate')) {
                return response()->json([
                    'success' => false,
                    'error'   => ['code' => 'DUPLICATE_KEY', 'message' => 'A reservation already exists for this payout request.'],
                ], 409);
            }
            throw $e;
        }

        return (new PayoutReservationResource($reservation))
            ->response()
            ->setStatusCode(201);
    }

    public function approveReservation(Request $request, string $payoutId): JsonResponse
    {
        $approverId = (string) $request->user()?->id;

        try {
            $reservation = $this->service->approveReservation($payoutId, $approverId);
        } catch (PayoutSelfApprovalException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'SELF_APPROVAL_NOT_PERMITTED', 'message' => $e->getMessage()],
            ], 403);
        } catch (PayoutReservationExpiredException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'RESERVATION_EXPIRED', 'message' => $e->getMessage()],
            ], 409);
        }

        return (new PayoutReservationResource($reservation))->response();
    }

    public function rejectReservation(Request $request, string $payoutId): JsonResponse
    {
        Validator::make($request->all(), [
            'rejection_reason' => ['required', 'string', 'min:10'],
        ])->validate();

        $rejectorId  = (string) $request->user()?->id;
        $reservation = $this->service->rejectReservation(
            $payoutId,
            $request->input('rejection_reason'),
            $rejectorId,
        );

        return (new PayoutReservationResource($reservation))->response();
    }
}
