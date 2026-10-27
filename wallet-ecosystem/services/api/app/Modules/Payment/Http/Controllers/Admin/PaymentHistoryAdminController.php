<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\PaymentHistoryService;
use App\Modules\Payment\Http\Requests\GetPaymentHistoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class PaymentHistoryAdminController extends Controller
{
    public function __construct(
        private readonly PaymentHistoryService $historyService,
    ) {
    }

    public function index(GetPaymentHistoryRequest $request): JsonResponse
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel $user */
        $user = $request->user();

        $validated  = $request->validated();
        $hasPlatformPermission = $user->hasPermissionTo('payment.history.read.platform');
        $orgId = $validated['organisation_id'] ?? null;

        if ($hasPlatformPermission && $orgId === null) {
            $paginator = $this->historyService->getForPlatform($validated);
        } elseif ($hasPlatformPermission && $orgId !== null) {
            $paginator = $this->historyService->getForOrganisation($orgId, $validated);
        } else {
            // Org-scoped admin: must have payment.history.read.organisation
            $adminOrgId = $user->organisation_id ?? $orgId;

            if ($adminOrgId === null) {
                return response()->json(['message' => 'organisation_id is required for organisation-scoped access.'], 422);
            }

            $paginator = $this->historyService->getForOrganisation($adminOrgId, $validated);
        }

        return response()->json([
            'data' => collect($paginator->items())->filter()->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }
}
