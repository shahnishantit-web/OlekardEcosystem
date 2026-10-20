<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects\Providers;

use App\Modules\Platform\Domain\Enums\ProviderStatus;

final class ProviderHealthStatus
{
    public function __construct(
        public readonly ProviderStatus $status,
        public readonly \DateTimeImmutable $lastCheckedAt,
        public readonly ?string $reason,
    ) {}

    public static function up(\DateTimeImmutable $checkedAt): self
    {
        return new self(ProviderStatus::UP, $checkedAt, null);
    }

    public static function degraded(string $reason, \DateTimeImmutable $checkedAt): self
    {
        return new self(ProviderStatus::DEGRADED, $checkedAt, $reason);
    }

    public static function down(string $reason, \DateTimeImmutable $checkedAt): self
    {
        return new self(ProviderStatus::DOWN, $checkedAt, $reason);
    }

    public static function unknown(): self
    {
        return new self(
            ProviderStatus::UNKNOWN,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            null,
        );
    }
}
