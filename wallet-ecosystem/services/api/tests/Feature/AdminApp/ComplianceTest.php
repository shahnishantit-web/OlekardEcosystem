<?php

declare(strict_types=1);

namespace Tests\Feature\AdminApp;

use Tests\TestCase;

final class ComplianceTest extends TestCase
{
    public function test_audit_query_returns_events(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.audit.read')
            ->getJson('/v1/admin-app/audit?from_date=2026-10-01&to_date=2026-10-05');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_audit_91_day_range_returns_400(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.audit.read')
            ->getJson('/v1/admin-app/audit?from_date=2026-07-01&to_date=2026-10-05');

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'ADMINAPP_AUDIT_001');
    }

    public function test_operations_role_denied_audit(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.users.read')
            ->getJson('/v1/admin-app/audit');

        $response->assertStatus(403);
    }

    public function test_report_create_returns_202_with_report_id(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.reports.read')
            ->withHeaders(['Idempotency-Key' => 'report-create-test-001'])
            ->postJson('/v1/admin-app/reports', [
                'report_type'  => 'transaction_summary',
                'period_start' => '2026-10-01',
                'period_end'   => '2026-10-05',
                'format'       => 'csv',
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['data' => ['report_id', 'status']]);
    }

    public function test_idempotent_report_create_returns_same_report_id(): void
    {
        $key = 'idempotent-report-test-002';

        $first = $this->actingAsAdminWithPermission('adminapp.reports.read')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/admin-app/reports', [
                'report_type'  => 'transaction_summary',
                'period_start' => '2026-10-01',
                'period_end'   => '2026-10-05',
                'format'       => 'csv',
            ]);

        $second = $this->actingAsAdminWithPermission('adminapp.reports.read')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/admin-app/reports', [
                'report_type'  => 'transaction_summary',
                'period_start' => '2026-10-01',
                'period_end'   => '2026-10-05',
                'format'       => 'csv',
            ]);

        $first->assertStatus(202);
        $second->assertStatus(202);
        $this->assertEquals($first->json('data.report_id'), $second->json('data.report_id'));
    }
}
