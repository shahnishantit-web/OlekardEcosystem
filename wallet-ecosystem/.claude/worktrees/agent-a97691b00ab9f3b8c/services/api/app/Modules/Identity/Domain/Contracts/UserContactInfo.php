<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

readonly class UserContactInfo
{
    public function __construct(
        public string  $userId,
        public ?string $email,
        public ?string $mobileNumber,
        public string  $actorType,
    ) {}
}
