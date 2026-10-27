<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class RepairSingleAccountingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $paymentIntentId,
    ) {
    }

    public function uniqueId(): string
    {
        return 'payment_repair_accounting:'.$this->paymentIntentId;
    }

    public function handle(PaymentOrchestrationService $orchestration): void
    {
        $correlationId = (string) Uuid::uuid7();

        try {
            $orchestration->repairAccountingForIntent($this->paymentIntentId, $correlationId);
        } catch (\Throwable $e) {
            Log::warning('RepairSingleAccounting: unexpected failure', [
                'payment_intent_id' => $this->paymentIntentId,
                'error'             => $e->getMessage(),
                'correlation_id'    => $correlationId,
            ]);
            throw $e;
        }
    }
}
