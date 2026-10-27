<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\EventHandlers;

use App\Modules\Payment\Application\Services\ClearingService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class BankSettlementBatchHandler
{
    public function __construct(
        private readonly ClearingService      $clearingService,
        private readonly AuditServiceInterface $audit,
    ) {}

    /**
     * Handle 'bank.settlement.batch' inbox event.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $providerCode = $payload['provider_code'] ?? '';
        $batchRef     = $payload['batch_reference'] ?? '';
        $settledAtRaw = $payload['settled_at'] ?? now()->toIso8601String();
        $lines        = $payload['lines'] ?? [];

        $settledAt = Carbon::parse($settledAtRaw);

        $result = $this->clearingService->matchBankSettlementBatch(
            providerCode: $providerCode,
            batchRef:     $batchRef,
            settledAt:    $settledAt,
            lines:        $lines,
        );

        Log::info('payment.settlement.batch_received', [
            'provider_code' => $providerCode,
            'batch_ref'     => $batchRef,
            'matched'       => $result['matched'],
            'unmatched'     => $result['unmatched'],
        ]);
    }
}
