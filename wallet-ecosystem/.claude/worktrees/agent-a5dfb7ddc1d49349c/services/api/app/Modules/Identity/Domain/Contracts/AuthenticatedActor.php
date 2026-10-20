<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

readonly class AuthenticatedActor
{
    public function __construct(
        public string  $userId,
        public string  $sessionId,
        public string  $actorType,   // USER | SERVICE | SYSTEM
        public array   $roles,
        public ?string $deviceId,
    ) {}
}
