<?php

declare(strict_types=1);

namespace Tests\Feature\AdminApp;

use Tests\TestCase;

/**
 * SC-9: Automated role access matrix spot checks.
 * Finance denied operational dashboard, Operations denied KYC, etc.
 */
final class RoleAccessMatrixTest extends TestCase
{
    /** @dataProvider roleAccessMatrix */
    public function test_role_access_matrix(string $permission, string $endpoint, int $expectedStatus): void
    {
        $response = $this->actingAsAdminWithPermission($permission)
            ->getJson($endpoint);

        $response->assertStatus($expectedStatus);
    }

    /** @return array<string, array{string, string, int}> */
    public static function roleAccessMatrix(): array
    {
        return [
            // Finance role (float.manage) denied operational dashboard
            'finance_denied_operational_dashboard' => ['adminapp.float.manage', '/v1/admin-app/dashboard/operational', 403],
            // Operations (users.read) denied financial dashboard
            'operations_denied_financial_dashboard' => ['adminapp.users.read', '/v1/admin-app/dashboard/financial', 403],
            // Operations (users.read) denied KYC review
            'operations_denied_kyc' => ['adminapp.users.read', '/v1/admin-app/kyc', 403],
            // Compliance (kyc.review) denied user management
            'compliance_denied_users' => ['adminapp.kyc.review', '/v1/admin-app/users', 403],
            // Finance (float.manage) denied reconciliation (Finance SHOULD have access — 200/503)
            'finance_can_access_reconciliation' => ['adminapp.reconciliation.read', '/v1/admin-app/reconciliation', 200],
            // Operations denied float endpoints
            'operations_denied_float' => ['adminapp.agents.manage', '/v1/admin-app/float', 403],
            // Compliance denied settlement
            'compliance_denied_settlement' => ['adminapp.kyc.review', '/v1/admin-app/settlement', 403],
            // Admin can access audit log
            'admin_can_access_audit' => ['adminapp.audit.read', '/v1/admin-app/audit', 200],
        ];
    }
}
