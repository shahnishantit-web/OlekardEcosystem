<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Jobs;

use App\Modules\Wallet\Application\Services\WalletProvisioningService;
use App\Modules\Wallet\Domain\Exceptions\WalletAlreadyExistsException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ProcessKycApprovedForWalletJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        private readonly string $userId,
        private readonly string $correlationId,
    ) {
        $this->onQueue('wallet-provisioning');
    }

    public function handle(WalletProvisioningService $service): void
    {
        try {
            $service->provisionForUser($this->userId, $this->correlationId);
        } catch (WalletAlreadyExistsException) {
            // Idempotent: wallet already exists for this user — log warning, not exception
            Log::warning('wallet.provision.already_exists', [
                'user_id'        => $this->userId,
                'correlation_id' => $this->correlationId,
            ]);
        }
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function failed(\Throwable $e): void
    {
        Log::error('wallet.provision.failed', [
            'user_id'        => $this->userId,
            'correlation_id' => $this->correlationId,
            'error'          => $e->getMessage(),
        ]);
    }
}
