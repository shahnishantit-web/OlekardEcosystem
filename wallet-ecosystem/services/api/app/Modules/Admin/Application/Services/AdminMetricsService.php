<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use App\Modules\Admin\Domain\Contracts\AdminMetricsInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;

final class AdminMetricsService implements AdminMetricsInterface
{
    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    public function userAction(string $actionType): void
    {
        $this->metrics->increment('admin.user.action', ['action_type' => $actionType]);
    }

    public function walletAction(string $actionType): void
    {
        $this->metrics->increment('admin.wallet.action', ['action_type' => $actionType]);
    }

    public function adjustmentTotal(string $status): void
    {
        $this->metrics->increment('admin.wallet.adjustment.total', ['status' => $status]);
    }

    public function transactionAction(string $actionType): void
    {
        $this->metrics->increment('admin.transaction.action', ['action_type' => $actionType]);
    }

    public function exportRows(int $count, string $format): void
    {
        $this->metrics->increment('admin.export.request', ['format' => $format]);
        $this->metrics->histogram('admin.export.rows', (float) $count, ['format' => $format]);
    }

    public function dashboardLoad(int $ms, bool $cached): void
    {
        $this->metrics->histogram('admin.dashboard.load_ms', (float) $ms, ['cached' => $cached ? 'true' : 'false']);
    }
}
