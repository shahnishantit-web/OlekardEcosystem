<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

interface UserContactServiceInterface
{
    public function userExists(string $userId): bool;

    public function getPhoneForUser(string $userId): ?string;

    public function getEmailForUser(string $userId): ?string;
}
