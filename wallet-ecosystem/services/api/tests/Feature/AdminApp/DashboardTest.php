<?php

declare(strict_types=1);

namespace Tests\Feature\AdminApp;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    public function test_operational_dashboard_returns_200_for_admin(): void
    {
        $this->seedDashboardCache();

        $response = $this->actingAsAdminWithPermission('adminapp.dashboard.operational')
            ->getJson('/v1/admin-app/dashboard/operational');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'cached_at',
                    'total_users',
                    'active_users_today',
                    'pending_kyc_count',
                    'total_transactions_today',
                    'active_agents',
                ],
            ]);
    }

    public function test_financial_dashboard_returns_403_for_operations_role(): void
    {
        $this->seedDashboardCache();

        $response = $this->actingAsAdminWithPermission('adminapp.dashboard.operational')
            ->getJson('/v1/admin-app/dashboard/financial');

        $response->assertStatus(403);
    }

    public function test_dashboard_returns_503_when_cache_empty(): void
    {
        Redis::del('adminapp:dashboard:operational');

        $response = $this->actingAsAdminWithPermission('adminapp.dashboard.operational')
            ->getJson('/v1/admin-app/dashboard/operational');

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'ADMINAPP_DASH_001');
    }

    public function test_cached_at_present_in_operational_response(): void
    {
        $this->seedDashboardCache();

        $response = $this->actingAsAdminWithPermission('adminapp.dashboard.operational')
            ->getJson('/v1/admin-app/dashboard/operational');

        $response->assertStatus(200)
            ->assertJsonPath('data.cached_at', fn ($v) => $v !== null);
    }

    public function test_financial_dashboard_returns_200_for_finance_role(): void
    {
        $this->seedDashboardCache();

        $response = $this->actingAsAdminWithPermission('adminapp.dashboard.financial')
            ->getJson('/v1/admin-app/dashboard/financial');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['cached_at', 'total_wallet_balance_minor_units']]);
    }

    public function test_risk_dashboard_returns_200_for_compliance_role(): void
    {
        $this->seedDashboardCache();

        $response = $this->actingAsAdminWithPermission('adminapp.dashboard.risk')
            ->getJson('/v1/admin-app/dashboard/risk');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['cached_at', 'active_fraud_alerts']]);
    }

    private function seedDashboardCache(): void
    {
        $cachedAt = now()->toIso8601ZuluString();

        Redis::setex('adminapp:dashboard:operational', 300, json_encode([
            'cached_at' => $cachedAt, 'total_users' => 100, 'active_users_today' => 20,
            'new_registrations_today' => 5, 'pending_kyc_count' => 3, 'approved_kyc_today' => 8,
            'total_transactions_today' => 150, 'transaction_volume_today_minor_units' => 500000,
            'transaction_currency' => 'BWP', 'active_agents' => 45, 'pending_agent_approvals' => 2,
            'open_disputes' => 1, 'open_fraud_alerts' => 0,
        ]));

        Redis::setex('adminapp:dashboard:financial', 300, json_encode([
            'cached_at' => $cachedAt, 'total_wallet_balance_minor_units' => 10000000,
            'balance_currency' => 'BWP', 'total_funding_today_minor_units' => 500000,
            'total_withdrawals_today_minor_units' => 200000, 'total_transfers_today_minor_units' => 300000,
            'total_fees_collected_today_minor_units' => 5000, 'total_commissions_paid_today_minor_units' => 1500,
            'total_float_outstanding_minor_units' => 2000000, 'pending_settlements_count' => 0,
            'pending_settlements_amount_minor_units' => 0,
        ]));

        Redis::setex('adminapp:dashboard:risk', 300, json_encode([
            'cached_at' => $cachedAt, 'active_fraud_alerts' => 0, 'high_risk_transactions_today' => 2,
            'aml_flags_today' => 0, 'blocked_users_count' => 3, 'frozen_wallets_count' => 1,
            'failed_kyc_attempts_today' => 2, 'suspicious_ip_count_today' => 0,
            'open_support_disputes' => 1, 'velocity_breach_alerts_today' => 0,
        ]));
    }
}
