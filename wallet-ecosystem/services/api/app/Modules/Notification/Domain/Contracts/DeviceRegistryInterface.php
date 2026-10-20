<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

use App\Modules\Notification\Domain\DTO\DeviceTokenRecord;

interface DeviceRegistryInterface
{
    /** @return DeviceTokenRecord[] */
    public function getActiveTokensForUser(string $userId): array;

    public function invalidateToken(string $tokenId): void;
}
