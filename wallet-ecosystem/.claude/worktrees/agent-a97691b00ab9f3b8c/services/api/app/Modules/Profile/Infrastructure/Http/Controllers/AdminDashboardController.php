<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Controllers;

use App\Modules\Profile\Application\Queries\GetDashboardSummaryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Admin profile oversight dashboard.
 * Returns aggregate KPI counts — no PII. Authorization enforced by ProfilePermissionMiddleware.
 */
final class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly GetDashboardSummaryQuery $dashboardQuery,
    ) {}

    /**
     * GET /v1/admin/profile/dashboard/summary
     * Requires profile.read permission (enforced at route level by ProfilePermissionMiddleware).
     */
    public function summary(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $dto = $this->dashboardQuery->execute();

        return response()->json([
            'success'        => true,
            'data'           => [
                'total_users'              => $dto->totalUsers,
                'active_accounts'          => $dto->activeAccounts,
                'suspended_accounts'       => $dto->suspendedAccounts,
                'pending_closure_requests' => $dto->pendingClosureRequests,
                'recent_contact_changes'   => $dto->recentContactChanges,
            ],
            'error'          => null,
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }
}
