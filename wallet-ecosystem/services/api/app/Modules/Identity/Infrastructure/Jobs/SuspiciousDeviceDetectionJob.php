<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Jobs;

use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Services\DeviceSecurityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Horizon job: runs suspicious device detection rules asynchronously.
 * Non-blocking — catches all exceptions and logs without rethrowing.
 *
 * Canonical dispatch signature:
 *   SuspiciousDeviceDetectionJob::dispatch($deviceId, $userId, $fingerprintHash, $isEmulator = false)
 */
final class SuspiciousDeviceDetectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $deviceId,
        private readonly string $userId,
        private readonly string $fingerprintHash,
        private readonly bool $isEmulator = false,
    ) {
        $this->onQueue('default');
    }

    public function handle(DeviceRepositoryInterface $devices, DeviceSecurityService $securityService): void
    {
        try {
            $device = $devices->findById($this->deviceId);

            if ($device === null) {
                Log::warning('SuspiciousDeviceDetectionJob: device not found', [
                    'device_id' => $this->deviceId,
                ]);
                return;
            }

            $securityService->detectSuspicious(
                device:        $device,
                userId:        $this->userId,
                isEmulator:    $this->isEmulator,
                correlationId: (string) Uuid::uuid7(),
            );
        } catch (\Throwable $e) {
            Log::error('SuspiciousDeviceDetectionJob failed', [
                'device_id' => $this->deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
