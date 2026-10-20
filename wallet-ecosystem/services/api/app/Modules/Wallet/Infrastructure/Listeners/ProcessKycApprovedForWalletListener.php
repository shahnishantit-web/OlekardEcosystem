<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Listeners;

use App\Modules\Wallet\Infrastructure\Jobs\ProcessKycApprovedForWalletJob;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class ProcessKycApprovedForWalletListener
{
    /**
     * Consume Kyc.Case.Approved outbox event dispatched by InProcessDriver.
     * Dispatches ProcessKycApprovedForWalletJob to the wallet-provisioning queue.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        $userId = $payload['user_id'] ?? null;
        if ($userId === null) {
            Log::warning('wallet.kyc.approved.missing_user_id', ['payload' => $payload]);
            return;
        }

        $correlationId = $payload['correlation_id'] ?? (string) Uuid::uuid7();

        ProcessKycApprovedForWalletJob::dispatch($userId, $correlationId);
    }
}
