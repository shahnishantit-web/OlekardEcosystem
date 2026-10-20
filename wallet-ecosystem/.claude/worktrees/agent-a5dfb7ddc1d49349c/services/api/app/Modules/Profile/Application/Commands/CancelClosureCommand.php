<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Commands;

final readonly class CancelClosureCommand
{
    public function __construct(
        public string  $userId,
        public string  $actorId,
        public string  $actorType,
        public string  $correlationId,
        public ?string $reason = null,
    ) {}
}
