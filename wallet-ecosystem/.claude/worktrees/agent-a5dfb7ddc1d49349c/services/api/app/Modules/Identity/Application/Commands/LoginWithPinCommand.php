<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class LoginWithPinCommand
{
    public function __construct(
        public string  $identifier,
        public string  $pin,
        public string  $correlationId,
        public string  $idempotencyKey,
        public string  $ipHash,
        public string  $rawDeviceFingerprint,
        public string  $deviceName,
        public string  $platform,
    ) {}
}
