<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

readonly class JwkPublicKey
{
    public function __construct(
        public string $kid,
        public string $kty,   // 'EC'
        public string $crv,   // 'P-256'
        public string $use,   // 'sig'
        public string $alg,   // 'ES256'
        public string $x,     // base64url-encoded x-coordinate
        public string $y,     // base64url-encoded y-coordinate
    ) {}

    public function toArray(): array
    {
        return [
            'kty' => $this->kty,
            'crv' => $this->crv,
            'use' => $this->use,
            'alg' => $this->alg,
            'kid' => $this->kid,
            'x'   => $this->x,
            'y'   => $this->y,
        ];
    }
}
