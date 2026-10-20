<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Services;

use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;

final class SecurityAuditWriter
{
    public function __construct(private readonly AuditModuleContract $audit) {}

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function record(string $action, string $resource, string $id, string $actor, string $correlation, array $before = [], array $after = []): void
    {
        $this->audit->record(AuditEntry::make(AuditEventCategory::Security, $action, AuditActorType::Admin, $action, AuditOutcome::Success, $correlation)->withActor($actor)->withEntity($resource, $id)->withBeforeState($before)->withAfterState($after));
    }
}
