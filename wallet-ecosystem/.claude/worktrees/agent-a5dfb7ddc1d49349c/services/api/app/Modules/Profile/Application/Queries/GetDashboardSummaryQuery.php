<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Queries;

use App\Modules\Profile\Application\DTOs\AdminDashboardSummaryDTO;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates profile health KPIs from profile.user_profiles.
 * Returns only counts — no PII is exposed (spec §2 "without exposing raw PII").
 */
final class GetDashboardSummaryQuery
{
    public function execute(): AdminDashboardSummaryDTO
    {
        $row = DB::connection('profile')
            ->table('profile.user_profiles')
            ->selectRaw("
                COUNT(*)                                                             AS total_users,
                COUNT(*) FILTER (WHERE account_status = 'active')                   AS active_accounts,
                COUNT(*) FILTER (WHERE account_status = 'suspended')                AS suspended_accounts,
                COUNT(*) FILTER (WHERE account_status = 'pending_closure')          AS pending_closure_requests,
                COUNT(*) FILTER (WHERE pending_email IS NOT NULL
                                    OR pending_mobile IS NOT NULL)                   AS recent_contact_changes
            ")
            ->first();

        return new AdminDashboardSummaryDTO(
            totalUsers:             (int) ($row?->total_users             ?? 0),
            activeAccounts:         (int) ($row?->active_accounts         ?? 0),
            suspendedAccounts:      (int) ($row?->suspended_accounts      ?? 0),
            pendingClosureRequests: (int) ($row?->pending_closure_requests ?? 0),
            recentContactChanges:   (int) ($row?->recent_contact_changes  ?? 0),
        );
    }
}
