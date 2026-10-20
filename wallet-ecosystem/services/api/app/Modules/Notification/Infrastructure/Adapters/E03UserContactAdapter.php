<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Adapters;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Notification\Domain\Contracts\UserContactServiceInterface;

final class E03UserContactAdapter implements UserContactServiceInterface
{
    public function __construct(
        private readonly IdentityModuleContract $identity,
    ) {}

    public function userExists(string $userId): bool
    {
        return $this->identity->getUserContactInfo($userId) !== null;
    }

    public function getPhoneForUser(string $userId): ?string
    {
        $info = $this->identity->getUserContactInfo($userId);

        return $info?->mobileNumber;
    }

    public function getEmailForUser(string $userId): ?string
    {
        $info = $this->identity->getUserContactInfo($userId);

        return $info?->email;
    }
}
