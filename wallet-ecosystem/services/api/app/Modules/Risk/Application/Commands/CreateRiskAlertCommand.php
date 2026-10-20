<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Commands;

final class CreateRiskAlertCommand
{
    public function __construct(
        public readonly string  $alertType,
        public readonly string  $severity,
        public readonly string  $subjectType,
        public readonly string  $subjectId,
        public readonly string  $title,
        public readonly string  $description,
        public readonly string  $actorId,
        public readonly ?string $correlationId  = null,
        public readonly ?string $assignedTo     = null,
        public readonly array   $eventIds       = [],
    ) {}
}
