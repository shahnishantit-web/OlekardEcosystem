<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class RegisterUserCommand
{
    public function __construct(
        public string  $actorType,
        public string  $correlationId,
        public string  $idempotencyKey,
        public string  $ipHash,
        public ?string $mobileNumber,
        public ?string $email,
    ) {}
}
