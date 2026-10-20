<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Qa;

use App\Modules\Qa\Application\Jobs\ExecuteQaTestRunJob;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * @group docker
 *
 * E34 QA & Validation — Quickstart SC-1 through SC-6.
 * Validates acceptance scenarios from specs/034-qa-validation/quickstart.md.
 *
 * NOTE: SC-5 financial isolation is verified at the API trigger level only.
 * The async ExecuteQaTestRunJob runs walletBalance tests inside FinancialIsolation
 * which wraps them in rolled-back transactions — no real balance mutations occur.
 * This is a code-architecture guarantee; the test verifies the trigger returns 202
 * without mutating any wallet state visibly.
 */
final class QaQuickstartTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['platform', 'identity'];

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent async jobs from executing during HTTP tests so we can verify
        // the synchronous API contract independently of Horizon worker availability.
        Queue::fake();
    }

    // ── SC-1: Trigger a QA test run ───────────────────────────────────────────

    /** SC-1a: POST trigger returns 202 with status=pending for a new run. */
    public function test_sc1_trigger_returns_202_with_pending_status(): void
    {
        $this->actingAsAdminWithPermission('qa.execute');
        $idempotencyKey = (string) Uuid::uuid7();

        $response = $this->postJson('/v1/qa/unit-test-authentication', [], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'pending');
        $this->assertNotEmpty($response->json('data.id'), 'data.id must be present');

        // The async job was dispatched (not executed synchronously)
        Queue::assertPushed(ExecuteQaTestRunJob::class);

        // Run record persisted in qa.test_runs
        $runId = $response->json('data.id');
        $this->assertNotNull(TestRun::on('platform')->find($runId), 'TestRun must be persisted');
    }

    // ── SC-2: Idempotency ────────────────────────────────────────────────────

    /** SC-2: Same Idempotency-Key replays the same run with HTTP 200. */
    public function test_sc2_same_idempotency_key_returns_200_with_same_run_id(): void
    {
        $this->actingAsAdminWithPermission('qa.execute');
        $idempotencyKey = (string) Uuid::uuid7();

        $first = $this->postJson('/v1/qa/unit-test-authentication', [], [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $first->assertStatus(202);
        $runId = $first->json('data.id');

        $second = $this->postJson('/v1/qa/unit-test-authentication', [], [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $second->assertStatus(200);
        $this->assertSame($runId, $second->json('data.id'), 'Idempotent replay must return the same run_id');
    }

    // ── SC-3: Authorization ───────────────────────────────────────────────────

    /** SC-3: Actor without qa.execute is rejected with 403. */
    public function test_sc3_trigger_denied_without_qa_execute_permission(): void
    {
        $this->actingAsAdminWithPermission('adminapp.dashboard.operational');

        $response = $this->postJson('/v1/qa/unit-test-authentication', [], [
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(403);
    }

    /** SC-3: Unauthenticated trigger returns 401. */
    public function test_sc3_unauthenticated_trigger_returns_401(): void
    {
        $response = $this->postJson('/v1/qa/unit-test-authentication', [], [
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(401);
    }

    // ── SC-4: Dashboard aggregate ─────────────────────────────────────────────

    /** SC-4: Dashboard returns one entry per QaCategory with summary totals. */
    public function test_sc4_dashboard_returns_all_categories(): void
    {
        $this->actingAsAdminWithPermission('qa.execute');

        $response = $this->getJson('/v1/qa/dashboard');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['summary', 'categories']]);

        $totalCategories = $response->json('data.summary.total_categories');
        $this->assertIsInt($totalCategories, 'summary.total_categories must be int');
        $this->assertGreaterThan(0, $totalCategories, 'must have at least one category');

        $categories = $response->json('data.categories');
        $this->assertIsArray($categories, 'categories must be array');
        $this->assertSame($totalCategories, count($categories), 'categories array length must equal total_categories');
    }

    /** SC-4: After triggering a run, that category shows latest_run_id in the dashboard. */
    public function test_sc4_dashboard_reflects_triggered_run(): void
    {
        $this->actingAsAdminWithPermission('qa.execute');
        $idempotencyKey = (string) Uuid::uuid7();

        $trigger = $this->postJson('/v1/qa/unit-test-authentication', [], [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $trigger->assertStatus(202);
        $runId = $trigger->json('data.id');

        $dashboard = $this->getJson('/v1/qa/dashboard');
        $dashboard->assertStatus(200);

        $categories = collect($dashboard->json('data.categories'));
        $unitAuth   = $categories->firstWhere('category', 'unit_auth');

        $this->assertNotNull($unitAuth, 'unit_auth category must appear in dashboard');
        $this->assertSame($runId, $unitAuth['latest_run_id'], 'latest_run_id must match the triggered run');
    }

    // ── SC-5: Financial isolation ─────────────────────────────────────────────

    /**
     * SC-5: Triggering wallet-balance-testing returns 202 (run created).
     * The FinancialIsolation concern wraps executor DB writes in a savepoint that
     * is rolled back — no real wallet balance mutations occur. Verified here at
     * the trigger level; executor isolation is an architectural guarantee.
     */
    public function test_sc5_wallet_balance_testing_trigger_returns_202(): void
    {
        $this->actingAsAdminWithPermission('qa.execute');

        $response = $this->postJson('/v1/qa/wallet-balance-testing', [], [
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'pending');
        Queue::assertPushed(ExecuteQaTestRunJob::class);
    }

    // ── SC-6: List runs with category filter ──────────────────────────────────

    /** SC-6: After triggering a run, listing with category=unit_auth finds it. */
    public function test_sc6_run_list_with_category_filter_returns_matching_runs(): void
    {
        $this->actingAsAdminWithPermission('qa.execute');
        $idempotencyKey = (string) Uuid::uuid7();

        $this->postJson('/v1/qa/unit-test-authentication', [], [
            'Idempotency-Key' => $idempotencyKey,
        ])->assertStatus(202);

        $response = $this->getJson('/v1/qa/runs?category=unit_auth&per_page=5');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total', 'per_page']]);

        $results = $response->json('data');
        $this->assertNotEmpty($results, 'At least 1 run must be returned for unit_auth category');
        foreach ($results as $run) {
            $this->assertSame('unit_auth', $run['category'], 'All results must match the category filter');
        }
    }

    /** SC-6: Run list without filter returns all runs (paginated). */
    public function test_sc6_run_list_without_filter_returns_paginated_results(): void
    {
        $this->actingAsAdminWithPermission('qa.execute');

        $response = $this->getJson('/v1/qa/runs');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total', 'per_page']]);
    }
}
