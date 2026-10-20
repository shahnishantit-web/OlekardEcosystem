<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class LoginWithBiometricCommand
{
    public function __construct(
        public string  $userId,
        public string  $credentialId,
        public string  $signedAssertion,
        public string  $nonce,
        public string  $correlationId,
        public string  $idempotencyKey,
        public string  $ipHash,
        public string  $rawDeviceFingerprint,
        public string  $deviceName,
        public string  $platform,
    ) {}
}
