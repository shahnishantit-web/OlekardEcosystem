<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\ValueObjects\PaymentCapabilities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;

/**
 * T024: Poll provider for a submitted/pending/unknown bank refund status.
 *
 * Self-reschedules on pending-no-change until max retries, then transitions to Unknown.
 */
final class QueryRefundStatusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly string $refundId,
    ) {
    }

    public function handle(
        PaymentRefundRepositoryInterface $refundRepo,
        RefundService                    $refundService,
        ConfigurationServiceInterface    $config,
        Container                        $container,
    ): void {
        $refund = $refundRepo->findById($this->refundId);

        if ($refund === null) {
            Log::warning('QueryRefundStatusJob: refund not found', ['refund_id' => $this->refundId]);
            return;
        }

        if ($refund->status->isTerminal()) {
            Log::debug('QueryRefundStatusJob: refund already terminal', [
                'refund_id' => $this->refundId,
                'status'    => $refund->status->value,
            ]);
            return;
        }

        $maxAttempts     = (int) $config->getOrDefault('payment.refund_max_query_attempts', '24');
        $queryIntervalSecs = (int) $config->getOrDefault('payment.refund_query_interval_seconds', '300');

        if (($refund->query_attempts ?? 0) >= $maxAttempts) {
            Log::warning('QueryRefundStatusJob: max attempts reached, marking unknown', [
                'refund_id'      => $this->refundId,
                'query_attempts' => $refund->query_attempts,
            ]);
            $refundService->markUnknown($this->refundId, "query_exhausted:{$this->refundId}");
            return;
        }

        $providerRef = $refund->provider_submitted_ref ?? $refund->provider_refund_reference ?? '';

        try {
            $adapterKey = 'payment.adapter.' . ($refund->payment_attempt_id ?? 'unknown');
            if (! $container->bound($adapterKey)) {
                Log::error('QueryRefundStatusJob: no adapter registered', ['refund_id' => $this->refundId]);
                return;
            }

            $adapter = PaymentCapabilities::refund($container->make($adapterKey));
            $result  = $adapter->queryRefundStatus($this->refundId, $providerRef);

            Log::debug('QueryRefundStatusJob: polled provider', [
                'refund_id' => $this->refundId,
                'status'    => $result->status,
            ]);

            if ($result->isConfirmed()) {
                $refundService->confirm(
                    $this->refundId,
                    $result->providerRef ?? $providerRef,
                    "query:{$this->refundId}"
                );
                return;
            }

            if ($result->isDefinitiveFailure()) {
                $refundService->definitivelyFail(
                    $this->refundId,
                    "Provider returned definitive failure via status query: {$result->rawProviderCode}",
                    "query:{$this->refundId}"
                );
                return;
            }

            // Still pending — increment query_attempts and reschedule
            $refundService->markPending($this->refundId, "query:{$this->refundId}");
            static::dispatch($this->refundId)->delay(now()->addSeconds($queryIntervalSecs));

        } catch (\Throwable $e) {
            Log::error('QueryRefundStatusJob: query failed', [
                'refund_id' => $this->refundId,
                'error'     => $e->getMessage(),
            ]);

            static::dispatch($this->refundId)->delay(now()->addSeconds($queryIntervalSecs));
        }
    }
}
