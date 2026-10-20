<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Commands;

final readonly class PermanentlyCloseAccountCommand
{
    public function __construct(
        public string  $targetUserId,
        public string  $actorId,
        public string  $correlationId,
        public ?string $reason = null,
    ) {}
}
