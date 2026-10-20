<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\DTOs;

final readonly class AdminDashboardSummaryDTO
{
    public function __construct(
        public int $totalUsers,
        public int $activeAccounts,
        public int $suspendedAccounts,
        public int $pendingClosureRequests,
        public int $recentContactChanges,
    ) {}
}
