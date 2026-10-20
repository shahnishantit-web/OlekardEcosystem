<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Commands;

final readonly class RequestDataExportCommand
{
    public function __construct(
        public string $userId,
        public string $correlationId,
    ) {}
}
