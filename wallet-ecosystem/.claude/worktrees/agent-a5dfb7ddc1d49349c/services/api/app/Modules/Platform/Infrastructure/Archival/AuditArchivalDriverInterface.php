<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Archival;

interface AuditArchivalDriverInterface
{
    /** @param string[] $eventIds */
    public function archive(array $eventIds): void;
}
