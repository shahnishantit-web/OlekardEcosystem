<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use App\Modules\Security\Domain\Contracts\SecurityAuditSource;
use App\Modules\Security\Infrastructure\Audit\ReadsSecurityAuditEvents;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class SecurityAuditEventSource implements SecurityAuditSource
{
    use ReadsSecurityAuditEvents;

    protected function query(): Builder
    {
        return DB::connection('platform')->table('platform.audit_events');
    }

    protected function source(): string
    {
        return 'platform';
    }

    protected function resourceType(): string
    {
        return 'resource_type';
    }

    protected function resourceId(): string
    {
        return 'resource_id';
    }

    protected function severityJson(): string
    {
        return "(payload::jsonb ->> 'severity')";
    }
}
