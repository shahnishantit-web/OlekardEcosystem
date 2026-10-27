<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class RepairPendingAccountingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Uniqueness key prevents overlapping scan jobs via Laravel Horizon.
     */
    public function uniqueId(): string
    {
        return 'payment_repair_accounting_scan';
    }

    public function handle(PaymentIntentRepositoryInterface $repo): void
    {
        $pending = $repo->findPendingAccounting(limit: 500);

        foreach ($pending as $intent) {
            RepairSingleAccountingJob::dispatch($intent->id);
        }

        Log::info('RepairPendingAccounting: dispatched repair jobs', ['count' => $pending->count()]);
    }
}
