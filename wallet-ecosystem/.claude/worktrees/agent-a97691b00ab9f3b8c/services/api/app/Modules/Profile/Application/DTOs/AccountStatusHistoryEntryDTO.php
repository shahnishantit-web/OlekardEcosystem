<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\DTOs;

final readonly class AccountStatusHistoryEntryDTO
{
    public function __construct(
        public ?string $fromStatus,
        public string  $toStatus,
        public ?string $reason,
        public string  $actorType,
        public ?string $actorId,   // null on user path; populated on admin path
        public string  $createdAt,
    ) {}
}
