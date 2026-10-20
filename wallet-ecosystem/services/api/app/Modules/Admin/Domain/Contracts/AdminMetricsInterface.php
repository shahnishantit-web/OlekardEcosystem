<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

interface AdminMetricsInterface
{
    public function userAction(string $actionType): void;
    public function walletAction(string $actionType): void;
    public function adjustmentTotal(string $status): void;
    public function transactionAction(string $actionType): void;
    public function exportRows(int $count, string $format): void;
    public function dashboardLoad(int $ms, bool $cached): void;
}
