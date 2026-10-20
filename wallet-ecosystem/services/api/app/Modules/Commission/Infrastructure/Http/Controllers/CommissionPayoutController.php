<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Controllers;

use App\Modules\Commission\Application\Services\CommissionPayoutService;
use App\Modules\Commission\Domain\Exceptions\CommissionPayoutNotFoundException;
use App\Modules\Commission\Domain\Exceptions\PayoutCancellationNotAllowedException;
use App\Modules\Commission\Infrastructure\Http\Requests\ApproveCommissionPayoutRequest;
use App\Modules\Commission\Infrastructure\Http\Resources\CommissionPayoutResource;
use App\Modules\Commission\Infrastructure\Models\CommissionPayoutModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class CommissionPayoutController extends Controller
{
    public function __construct(private readonly CommissionPayoutService $service) {}

    public function store(ApproveCommissionPayoutRequest $request): JsonResponse
    {

        $payout = $this->service->approveAndExecute(
            adminId:        (string) $request->attributes->get('identity.user_id'),
            agentId:        $request->validated('agent_id'),
            commissionIds:  $request->validated('commission_ids'),
            correlationId:  $request->header('X-Correlation-Id', Str::uuid()->toString()),
        );

        return (new CommissionPayoutResource($payout->load('commissions')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $payoutId): CommissionPayoutResource
    {

        $payout = CommissionPayoutModel::with('commissions')->find($payoutId);
        if ($payout === null) {
            throw new CommissionPayoutNotFoundException($payoutId);
        }

        return new CommissionPayoutResource($payout);
    }

    public function cancel(Request $request, string $payoutId): JsonResponse
    {

        try {
            $payout = $this->service->cancel(
                payoutId:      $payoutId,
                actorId:       (string) $request->attributes->get('identity.user_id'),
                correlationId: $request->header('X-Correlation-Id', Str::uuid()->toString()),
            );
        } catch (PayoutCancellationNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], 409);
        }

        return (new CommissionPayoutResource($payout->load('commissions')))
            ->response()
            ->setStatusCode(200);
    }
}
