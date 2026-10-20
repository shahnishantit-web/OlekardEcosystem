<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ramsey\Uuid\Uuid;

final class PaymentIntentExpiredJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function handle(
        PaymentIntentRepositoryInterface $intentRepository,
        PaymentOrchestrationService      $orchestration,
    ): void {
        $expiring = $intentRepository->findExpiring(new \DateTimeImmutable());

        foreach ($expiring as $intent) {
            $correlationId = $intent->correlation_id ?? (string) Uuid::uuid7();

            try {
                $orchestration->expireIntent($intent->id, $correlationId);
            } catch (\Throwable) {
                // Log and continue to next; do not abort the batch
                \Illuminate\Support\Facades\Log::error('Failed to expire payment intent', [
                    'intent_id'      => $intent->id,
                    'correlation_id' => $correlationId,
                ]);
            }
        }
    }
}
