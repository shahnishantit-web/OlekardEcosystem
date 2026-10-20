<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

final readonly class AuthenticatedSessionDTO
{
    public function __construct(
        public string $userId,
        public string $sessionId,
        public string $accessToken,
        public string $refreshToken,
        public string $tokenType,
        public int    $expiresIn,
        public bool   $newDeviceDetected = false,
    ) {}
}
