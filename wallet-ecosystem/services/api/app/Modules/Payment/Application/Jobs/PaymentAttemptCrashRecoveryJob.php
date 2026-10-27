<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class PaymentAttemptCrashRecoveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Uniqueness key prevents multiple overlapping scan jobs via Laravel Horizon.
     */
    public function uniqueId(): string
    {
        return 'payment_crash_recovery_scan';
    }

    public function handle(
        PaymentAttemptRepositoryInterface $repo,
        ConfigurationServiceInterface     $config,
    ): void {
        $threshold = (int) ($config->getOrDefault('payment.crash_recovery_threshold_seconds', 60) ?? 60);

        $stuck = $repo->findStuckInSubmitting($threshold);

        foreach ($stuck as $attempt) {
            PaymentAttemptSingleRecoveryJob::dispatch($attempt->id);
        }

        $count = $stuck->count();
        Log::info('CrashRecoveryScan: dispatched recovery jobs', ['count' => $count, 'threshold_seconds' => $threshold]);
    }
}
