<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

/**
 * Value object carrying all fields for an audit event record.
 *
 * IMPORTANT: beforeState and afterState MUST be pre-masked by the caller before passing to
 * AuditService — PiiMasker must be applied before construction.
 *
 * action naming MUST follow {module}.{aggregate}.{verb} lower-kebab-case (FR-183).
 */
final readonly class AuditEventData
{
    public function __construct(
        public string $action,
        public string $actorType,
        public string $resourceType,
        public string $correlationId,
        public string $outcome,          // success | failure | partial
        public ?string $actorId = null,
        public ?string $actorNameMasked = null,
        public ?string $resourceId = null,
        public ?array $beforeState = null,
        public ?array $afterState = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public array $metadata = [],
    ) {}
}
