<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Repositories;

use App\Modules\Security\Domain\Contracts\SecurityAuditSource;
use App\Modules\Security\Infrastructure\Audit\ReadsSecurityAuditEvents;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class SecurityAuditEventSource implements SecurityAuditSource
{
    use ReadsSecurityAuditEvents;

    protected function query(): Builder
    {
        return DB::connection('platform')->table('audit.audit_events');
    }

    protected function source(): string
    {
        return 'audit';
    }

    protected function resourceType(): string
    {
        return 'entity_type';
    }

    protected function resourceId(): string
    {
        return 'entity_id';
    }

    protected function severityJson(): string
    {
        return "(metadata::jsonb ->> 'severity')";
    }
}
