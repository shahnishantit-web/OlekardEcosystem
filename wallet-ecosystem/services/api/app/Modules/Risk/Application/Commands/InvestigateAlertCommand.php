<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Commands;

final class InvestigateAlertCommand
{
    public function __construct(
        public readonly string $alertId,
        public readonly string $assignedTo,
        public readonly int    $version,
        public readonly string $actorId,
    ) {}
}
