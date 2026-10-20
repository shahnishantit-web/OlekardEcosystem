<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

/**
 * Token pair DTO returned to API consumers after successful authentication.
 * Mirrors the fields in AuthenticatedSessionDTO without internal-only fields.
 */
final readonly class AuthTokenDTO
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $tokenType,
        public int    $expiresIn,
        public string $sessionId,
    ) {}
}
