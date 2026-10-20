<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application\DTOs;

final readonly class CreateApiClientData
{
    public function __construct(
        public string $name,
        /** @var string[] */
        public array $permissions,
        public int $rateLimitPerMinute = 60,
        public ?string $integrationId = null,
        public array $ipWhitelist = [],
    ) {}
}
