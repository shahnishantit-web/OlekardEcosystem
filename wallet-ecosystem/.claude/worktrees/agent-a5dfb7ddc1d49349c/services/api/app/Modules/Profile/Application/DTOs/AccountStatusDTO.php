<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\DTOs;

final readonly class AccountStatusDTO
{
    /** @param list<AccountStatusHistoryEntryDTO> $history */
    public function __construct(
        public string $accountStatus,
        public array  $history,
    ) {}
}
