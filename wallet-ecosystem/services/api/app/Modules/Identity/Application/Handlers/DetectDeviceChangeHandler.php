<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Handlers;

use App\Modules\Identity\Application\Commands\DetectDeviceChangeCommand;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Services\DeviceSecurityService;
use App\Modules\Identity\Infrastructure\Jobs\SuspiciousDeviceDetectionJob;

final class DetectDeviceChangeHandler
{
    public function __construct(
        private readonly DeviceRepositoryInterface $devices,
        private readonly DeviceSecurityService    $deviceSecurityService,
    ) {}

    public function handle(DetectDeviceChangeCommand $command): bool
    {
        $device = $this->devices->findById($command->deviceId);

        if ($device === null) {
            return false;
        }

        $changed = $this->deviceSecurityService->detectChange(
            device:        $device,
            submittedHash: $command->submittedFingerprintHash,
            userId:        $command->userId,
            actorType:     $command->actorType,
            correlationId: $command->correlationId,
            ipHash:        $command->ipHash,
        );

        if ($changed) {
            // Dispatch suspicious detection job — non-blocking; isEmulator defaults to false
            SuspiciousDeviceDetectionJob::dispatch(
                $command->deviceId,
                $command->userId,
                $command->submittedFingerprintHash,
                false,
            );
        }

        return $changed;
    }
}
