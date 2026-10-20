<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

readonly class ServiceTokenDTO
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public int    $expiresIn,
        public array  $scopes,
    ) {}
}
