<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Jobs;

use App\Modules\Kyc\Application\Services\KycVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class PerformAutomatedVerificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(private readonly string $caseId)
    {
        $this->onQueue('kyc-verification');
    }

    public function handle(KycVerificationService $service): void
    {
        $service->performVerification($this->caseId);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function failed(\Throwable $e): void
    {
        Log::error('kyc.provider.error', [
            'case_id' => $this->caseId,
            'error'   => $e->getMessage(),
        ]);
    }
}
