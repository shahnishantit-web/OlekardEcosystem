<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Handlers;

use App\Modules\Identity\Application\Commands\GenerateDeviceFingerprintCommand;
use App\Modules\Identity\Domain\Services\DeviceFingerprintService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;

final class GenerateDeviceFingerprintHandler
{
    private const ALLOWED_PLATFORMS = ['ios', 'android', 'web'];

    public function __construct(
        private readonly DeviceFingerprintService    $fingerprintService,
        private readonly AuditServiceInterface       $audit,
        private readonly ConfigurationServiceInterface $config,
    ) {}

    public function handle(GenerateDeviceFingerprintCommand $command): array
    {
        if (! in_array(strtolower($command->platform), self::ALLOWED_PLATFORMS, true)) {
            throw new \InvalidArgumentException(
                sprintf("Invalid platform '%s'. Must be one of: %s.", $command->platform, implode(', ', self::ALLOWED_PLATFORMS))
            );
        }

        $minBits = (int) $this->config->getOrDefault('device.fingerprint_min_entropy_bits', '32');

        $hash = $this->fingerprintService->computeHash($command->attributes, $command->platform);

        $this->fingerprintService->validateEntropy($command->attributes, $command->platform, $minBits);

        // Detect emulator indicators — result stored for metric/log only, no job dispatched here
        $isEmulator = $this->fingerprintService->detectEmulatorIndicators($command->attributes, $command->platform);

        $this->audit->record(new AuditEventData(
            action:        'identity.device.fingerprint-generated',
            actorType:     $command->actorType,
            resourceType:  'identity.device',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->userId,
            afterState:    ['platform' => $command->platform, 'is_emulator' => $isEmulator],
            ipAddress:     $command->ipHash,
        ));

        return [
            'fingerprint_hash' => $hash,
            'is_emulator'      => $isEmulator,
        ];
    }
}
