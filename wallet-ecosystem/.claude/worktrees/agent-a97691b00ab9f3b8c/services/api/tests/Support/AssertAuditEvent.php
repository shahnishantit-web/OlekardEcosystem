<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait AssertAuditEvent
{
    public function assertAuditEventRecorded(string $action, array $attributes = []): void
    {
        $query = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', $action);

        foreach ($attributes as $column => $value) {
            $query->where($column, $value);
        }

        $count = $query->count();

        $this->assertGreaterThan(
            0,
            $count,
            "Expected an audit event with action '{$action}' to be recorded, but none was found.",
        );
    }

    public function assertAuditEventNotRecorded(string $action): void
    {
        $count = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', $action)
            ->count();

        $this->assertEquals(
            0,
            $count,
            "Expected no audit event with action '{$action}', but one was found.",
        );
    }
}
