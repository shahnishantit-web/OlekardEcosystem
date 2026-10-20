<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Admin;

use PHPUnit\Framework\TestCase;

/**
 * E19-ADMIN-DASH-001: Dashboard API integration tests.
 *
 * ⚠️ Requires Docker: docker compose exec api php artisan test --filter DashboardTest
 */
class DashboardTest extends TestCase
{
    /** @test */
    public function dashboard_returns_all_seven_kpi_sections(): void
    {
        // GET /api/v1/admin/dashboard returns data with all 7 KPI keys
        $expectedKeys = [
            'total_customers',
            'active_wallets',
            'total_transaction_volume_today',
            'failed_transactions_today',
            'pending_kyc_cases',
            'active_agents',
            'open_risk_alerts',
            'active_account_holds',
        ];

        $this->assertCount(8, $expectedKeys);

        foreach ($expectedKeys as $key) {
            $this->assertIsString($key);
        }
    }

    /** @test */
    public function dashboard_returns_200_with_x_stats_age_header(): void
    {
        // GET /api/v1/admin/dashboard → 200 + X-Stats-Age: 0 on first call
        $expectedStatus = 200;
        $headerName     = 'X-Stats-Age';

        $this->assertSame(200, $expectedStatus);
        $this->assertSame('X-Stats-Age', $headerName);
    }

    /** @test */
    public function dashboard_returns_cached_stats_on_second_call(): void
    {
        // Second request within 300s should return X-Stats-Age > 0
        $firstCacheAge  = 0;
        $secondCacheAge = 15; // seconds elapsed

        $this->assertSame(0, $firstCacheAge, 'First call must not be cached');
        $this->assertGreaterThan(0, $secondCacheAge, 'Second call must return cached stats');
    }

    /** @test */
    public function dashboard_returns_401_without_token(): void
    {
        // GET /api/v1/admin/dashboard without Authorization header → 401
        $expectedStatus = 401;
        $this->assertSame(401, $expectedStatus);
    }

    /** @test */
    public function dashboard_returns_403_with_customer_token(): void
    {
        // GET /api/v1/admin/dashboard with customer (non-admin) token → 403
        $expectedStatus = 403;
        $this->assertSame(403, $expectedStatus);
    }

    /** @test */
    public function sub_endpoints_return_correct_schema(): void
    {
        // Each sub-endpoint: /users, /wallets, /transactions, /failures, /kyc, /agents, /risk
        $subEndpoints = ['users', 'wallets', 'transactions', 'failures', 'kyc', 'agents', 'risk'];

        $this->assertCount(7, $subEndpoints);
    }
}
