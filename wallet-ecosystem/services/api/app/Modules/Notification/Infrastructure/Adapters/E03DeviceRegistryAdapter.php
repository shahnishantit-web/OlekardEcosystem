<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Adapters;

use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Notification\Domain\Contracts\DeviceRegistryInterface;
use App\Modules\Notification\Domain\DTO\DeviceTokenRecord;

/**
 * Adapts E03's DeviceRepositoryInterface to E17's DeviceRegistryInterface.
 *
 * NOTE: E03's Device model does not yet carry push tokens (FCM/APNS).
 * Until E28 Platform Configuration adds token storage, this adapter returns an
 * empty token list — causing push deliveries to be silently skipped. In-app
 * is always the unconditional fallback channel.
 */
final class E03DeviceRegistryAdapter implements DeviceRegistryInterface
{
    public function __construct(
        /** @phpstan-ignore property.onlyWritten */
        private readonly DeviceRepositoryInterface $deviceRepository,
    ) {}

    /** @return DeviceTokenRecord[] */
    public function getActiveTokensForUser(string $userId): array
    {
        // Push token storage not yet implemented in E03; returns empty until E28.
        return [];
    }

    public function invalidateToken(string $tokenId): void
    {
        // No-op until push tokens are stored in E03.
    }
}
