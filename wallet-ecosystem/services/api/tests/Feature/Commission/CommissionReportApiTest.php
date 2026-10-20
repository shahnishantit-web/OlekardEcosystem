<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class CommissionReportApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_gets_paginated_report_with_totals(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.report.view']);
        $agent = AgentModel::factory()->create();

        CommissionModel::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'amount'   => 200,
            'status'   => CommissionStatus::Pending->value,
        ]);
        CommissionModel::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'amount'   => 300,
            'status'   => CommissionStatus::Paid->value,
        ]);

        $response = $this->actingAs($admin)->getJson('/v1/commissions/report');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('totals_by_status.pending', 600)
            ->assertJsonPath('totals_by_status.paid', 600);
    }

    public function test_status_filter_returns_only_matching(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.report.view']);
        $agent = AgentModel::factory()->create();

        CommissionModel::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'status'   => CommissionStatus::Pending->value,
        ]);
        CommissionModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionStatus::Paid->value,
        ]);

        $this->actingAs($admin)
            ->getJson('/v1/commissions/report?status=pending')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_transaction_type_filter(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.report.view']);
        $agent = AgentModel::factory()->create();

        CommissionModel::factory()->count(2)->create([
            'agent_id'         => $agent->id,
            'transaction_type' => 'cash_in',
        ]);
        CommissionModel::factory()->create([
            'agent_id'         => $agent->id,
            'transaction_type' => 'cash_out',
        ]);

        $this->actingAs($admin)
            ->getJson('/v1/commissions/report?transaction_type=cash_in')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_date_range_filter(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.report.view']);
        $agent = AgentModel::factory()->create();

        CommissionModel::factory()->count(2)->create([
            'agent_id'   => $agent->id,
            'created_at' => now()->subDays(5),
        ]);
        CommissionModel::factory()->create([
            'agent_id'   => $agent->id,
            'created_at' => now()->subDays(30),
        ]);

        $from = now()->subDays(10)->toDateString();
        $this->actingAs($admin)
            ->getJson("/v1/commissions/report?from={$from}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_non_admin_returns_403(): void
    {
        $user = $this->createAuthenticatedUser([]);
        $this->actingAs($user)->getJson('/v1/commissions/report')->assertStatus(403);
    }

    public function test_performance_with_large_dataset(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.report.view']);
        $agent = AgentModel::factory()->create();

        CommissionModel::factory()->count(500)->create(['agent_id' => $agent->id]);

        $start    = microtime(true);
        $response = $this->actingAs($admin)->getJson('/v1/commissions/report');
        $elapsed  = (microtime(true) - $start) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(
            500,
            $elapsed,
            "Report with 500 records should return in under 500ms (took {$elapsed}ms)",
        );
    }
}
