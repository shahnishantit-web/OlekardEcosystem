<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

readonly class JwkSigningKey
{
    public function __construct(
        public string $kid,
        public string $privateKeyPem,  // MUST NOT be logged, serialized to any response, or stored in config
        public string $algorithm,      // 'ES256'
    ) {}
}
