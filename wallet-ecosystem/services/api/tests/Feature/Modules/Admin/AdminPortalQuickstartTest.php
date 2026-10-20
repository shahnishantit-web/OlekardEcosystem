<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Admin;

use App\Modules\Admin\Domain\Enums\AdjustmentStatus;
use App\Modules\Admin\Domain\Models\AdminWalletAdjustment;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * @group docker
 *
 * E19 Admin Portal — Quickstart SC-1 through SC-10.
 * Validates the key acceptance scenarios from specs/019-admin-portal/quickstart.md.
 */
final class AdminPortalQuickstartTest extends TestCase
{
    use DatabaseTransactions;

    /** Add 'admin' schema connection to the wrapped transaction set. */
    protected $connectionsToTransact = [
        'platform',
        'identity',
        'profile',
        'wallet',
        'ledger',
        'kyc',
        'transaction',
        'risk',
        'agent',
        'notification',
        'funding',
        'transfer',
        'admin',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        Queue::fake();
    }

    // ── SC-1: Dashboard loads all KPI sections ─────────────────────────────────

    /** SC-1a: Dashboard returns 200 with all 7 KPI sections. */
    public function test_sc1_dashboard_returns_all_kpi_sections(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.read']);

        $response = $this->actingAs($admin)
            ->getJson('/v1/admin/dashboard?period=today');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['users', 'wallets', 'transactions', 'failures', 'kyc', 'agents', 'risk'],
        ]);
    }

    /** SC-1b: Dashboard response contains X-Stats-Age header. */
    public function test_sc1_dashboard_includes_x_stats_age_header(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.read']);

        $response = $this->actingAs($admin)
            ->getJson('/v1/admin/dashboard?period=today');

        $response->assertStatus(200);
        $this->assertTrue(
            $response->headers->has('X-Stats-Age'),
            'X-Stats-Age header must be present',
        );
    }

    // ── SC-2: Search and suspend a user; verify audit trail ────────────────────

    /** SC-2a: Suspend a customer user returns 200. */
    public function test_sc2_suspend_user_returns_200(): void
    {
        $customerId = $this->seedCustomer();
        $admin      = $this->createAuthenticatedAdmin(['admin.write']);

        $response = $this->actingAs($admin)
            ->postJson("/v1/admin/users/{$customerId}/suspend", [
                'reason' => 'Quickstart validation: suspend for SC-2 test',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.action_type', 'suspend_user');
    }

    public function test_user_list_and_detail_compose_profile_and_kyc_reads(): void
    {
        $userId = $this->seedCustomer();
        DB::connection('profile')->table('profile.user_profiles')->insert([
            'id' => (string) Uuid::uuid7(), 'user_id' => $userId,
            'first_name' => 'Yara', 'last_name' => 'QA', 'email' => 'yara.qa@test.local',
            'mobile_number' => '+26771112222', 'account_status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->createAuthenticatedAdmin(['admin.read']);

        $list = $this->actingAs($admin)->getJson('/v1/admin/users?q=Yara');
        $list->assertOk()
            ->assertJsonPath('data.0.id', $userId)
            ->assertJsonPath('data.0.profile.first_name', 'Yara')
            ->assertJsonPath('data.0.kyc', null);

        $detail = app(\App\Modules\Admin\Application\Services\UserAdminService::class)->getUserProfile($userId);
        $this->assertSame('Yara', $detail['profile']['first_name']);
        $this->assertNull($detail['kyc_status']);
        $this->assertNull($detail['kyc_tier']);
    }

    /** SC-2b: Duplicate suspend returns 409 Conflict. */
    public function test_sc2_duplicate_suspend_returns_409(): void
    {
        $customerId = $this->seedCustomer(status: 'suspended');
        $admin      = $this->createAuthenticatedAdmin(['admin.write']);

        $response = $this->actingAs($admin)
            ->postJson("/v1/admin/users/{$customerId}/suspend", [
                'reason' => 'Quickstart validation: duplicate suspend for SC-2 test',
            ]);

        $response->assertStatus(409);
    }

    /** SC-2c: Suspend unknown user returns 404. */
    public function test_sc2_suspend_unknown_user_returns_404(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.write']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/admin/users/' . Uuid::uuid7()->toString() . '/suspend', [
                'reason' => 'Should not find this user',
            ]);

        $response->assertStatus(404);
    }

    // ── SC-3: Reactivate a suspended user ──────────────────────────────────────

    /** SC-3: Reactivate a suspended user returns 200. */
    public function test_sc3_reactivate_user_returns_200(): void
    {
        $customerId = $this->seedCustomer(status: 'suspended');
        $admin      = $this->createAuthenticatedAdmin(['admin.write']);

        $response = $this->actingAs($admin)
            ->postJson("/v1/admin/users/{$customerId}/reactivate", [
                'reason' => 'Quickstart validation: reactivate for SC-3 test',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.action_type', 'reactivate_user');

        // Confirm status in DB
        $user = DB::connection('identity')->table('identity.users')->where('id', $customerId)->first();
        $this->assertSame('active', $user->status);
    }

    // ── SC-4: Search wallet, view balance, view ledger ─────────────────────────

    /** SC-4a: Wallet list endpoint returns 200 with paginated structure. */
    public function test_sc4_wallet_list_returns_paginated_response(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.read']);

        $response = $this->actingAs($admin)
            ->getJson('/v1/admin/wallets');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
    }

    /** SC-4b: Get non-existent wallet returns 404. */
    public function test_sc4_get_unknown_wallet_returns_404(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.read']);

        $response = $this->actingAs($admin)
            ->getJson('/v1/admin/wallets/' . Uuid::uuid7()->toString());

        $response->assertStatus(404);
    }

    // ── SC-5: Freeze wallet ────────────────────────────────────────────────────

    /** SC-5a: Freeze non-existent wallet returns 404. */
    public function test_sc5_freeze_unknown_wallet_returns_404(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['admin.freeze_wallet']);
        $checker = $this->createAuthenticatedAdmin(['admin.freeze_wallet']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/admin/wallets/' . Uuid::uuid7()->toString() . '/freeze', [
                'reason'     => 'AML investigation quickstart test',
                'checker_id' => $checker->id,
                'hold_type'  => 'aml_investigation',
            ]);

        $response->assertStatus(404);
    }

    /** SC-5b: Freeze with same maker and checker returns 422 (maker-checker violation). */
    public function test_sc5_freeze_self_checker_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.freeze_wallet']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/admin/wallets/' . Uuid::uuid7()->toString() . '/freeze', [
                'reason'     => 'Self checker test',
                'checker_id' => $admin->id, // same as actor
                'hold_type'  => 'risk_freeze',
            ]);

        $response->assertStatus(422);
    }

    // ── SC-6: Wallet adjustment maker-checker flow ─────────────────────────────

    /** SC-6a: Create credit adjustment returns 201 with pending_approval status. */
    public function test_sc6_create_adjustment_returns_pending_approval(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.write']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/admin/wallets/' . Uuid::uuid7()->toString() . '/adjustments', [
                'idempotency_key' => 'adj-qs-' . Uuid::uuid7()->toString(),
                'adjustment_type' => 'credit',
                'amount'          => '50.00',
                'currency'        => 'BWP',
                'reason'          => 'Compensatory credit for quickstart SC-6 test',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', AdjustmentStatus::PendingApproval->value);
        $response->assertJsonPath('data.adjustment_type', 'credit');
    }

    /** SC-6b: Same maker cannot approve — returns 422. */
    public function test_sc6_maker_cannot_self_approve_returns_422(): void
    {
        $maker  = $this->createAuthenticatedAdmin(['admin.write', 'admin.approve_adjustment']);
        $idemKey = 'adj-self-' . Uuid::uuid7()->toString();
        $walletId = Uuid::uuid7()->toString();

        $create = $this->actingAs($maker)
            ->postJson("/v1/admin/wallets/{$walletId}/adjustments", [
                'idempotency_key' => $idemKey,
                'adjustment_type' => 'credit',
                'amount'          => '25.00',
                'currency'        => 'BWP',
                'reason'          => 'Quickstart maker-checker test reason here',
            ]);

        $create->assertStatus(201);
        $adjId = $create->json('data.id');

        $approve = $this->actingAs($maker)
            ->postJson("/v1/admin/adjustments/{$adjId}/approve", [
                'reason' => 'Self-approval attempt',
            ]);

        $approve->assertStatus(422);
    }

    /** SC-6c: Duplicate idempotency key returns the existing record (idempotent). */
    public function test_sc6_duplicate_idempotency_key_returns_existing_record(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['admin.write']);
        $idemKey = 'adj-idem-' . Uuid::uuid7()->toString();
        $walletId = Uuid::uuid7()->toString();
        $payload = [
            'idempotency_key' => $idemKey,
            'adjustment_type' => 'credit',
            'amount'          => '10.00',
            'currency'        => 'BWP',
            'reason'          => 'Idempotency test reason for quickstart SC-6',
        ];

        $first  = $this->actingAs($admin)->postJson("/v1/admin/wallets/{$walletId}/adjustments", $payload);
        $second = $this->actingAs($admin)->postJson("/v1/admin/wallets/{$walletId}/adjustments", $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    // ── SC-7: Search and view transactions ─────────────────────────────────────

    /** SC-7a: Transaction list returns 200 with paginated structure. */
    public function test_sc7_transaction_list_returns_paginated_response(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.read']);

        $response = $this->actingAs($admin)
            ->getJson('/v1/admin/transactions');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
    }

    /** SC-7b: Get non-existent transaction returns 404. */
    public function test_sc7_get_unknown_transaction_returns_404(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.read']);

        $response = $this->actingAs($admin)
            ->getJson('/v1/admin/transactions/' . Uuid::uuid7()->toString());

        $response->assertStatus(404);
    }

    // ── SC-8: Reverse a transaction ────────────────────────────────────────────

    /** SC-8a: Reverse without Idempotency-Key header returns 400. */
    public function test_sc8_reverse_without_idempotency_key_returns_400(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.reverse_transaction']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/admin/transactions/' . Uuid::uuid7()->toString() . '/reverse', [
                'reason' => 'Test reversal without idempotency key',
            ]);

        $response->assertStatus(400);
    }

    /** SC-8b: Reverse non-existent transaction returns 404. */
    public function test_sc8_reverse_unknown_transaction_returns_404(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['admin.reverse_transaction']);
        $txnId  = Uuid::uuid7()->toString();
        $idemKey = 'rev-' . $txnId;

        $response = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $idemKey])
            ->postJson("/v1/admin/transactions/{$txnId}/reverse", [
                'reason' => 'Test reversal of unknown transaction',
            ]);

        $response->assertStatus(404);
    }

    // ── SC-9: Export transactions ───────────────────────────────────────────────

    /** SC-9: Export endpoint queues a job and returns 202 with export_id. */
    public function test_sc9_export_returns_202_with_export_id(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.export_transactions']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/admin/transactions/export', [
                'format' => 'csv',
                'from'   => now()->subDays(7)->format('Y-m-d'),
                'to'     => now()->format('Y-m-d'),
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'queued');
        $this->assertNotEmpty($response->json('data.export_id'));
    }

    // ── SC-10: Unauthorised access is rejected ─────────────────────────────────

    /** SC-10a: No auth token returns 401. */
    public function test_sc10_no_token_returns_401(): void
    {
        $response = $this->getJson('/v1/admin/dashboard');
        $response->assertStatus(401);
    }

    /** SC-10b: Customer user (non-admin) returns 403. */
    public function test_sc10_customer_token_returns_403(): void
    {
        $customerId = (string) Uuid::uuid7();
        $this->actingAsUser($customerId, 'CUSTOMER', []);

        $response = $this->getJson('/v1/admin/dashboard');
        $response->assertStatus(403);
    }

    /** SC-10c: Admin without required permission returns 403. */
    public function test_sc10_admin_without_permission_returns_403(): void
    {
        $admin = $this->createAuthenticatedAdmin([]); // no permissions

        $response = $this->actingAs($admin)
            ->getJson('/v1/admin/dashboard');

        $response->assertStatus(403);
    }

    /** SC-10d: Admin without freeze_wallet permission cannot freeze. */
    public function test_sc10_admin_without_freeze_permission_returns_403(): void
    {
        $admin = $this->createAuthenticatedAdmin(['admin.read']); // no freeze_wallet

        $response = $this->actingAs($admin)
            ->postJson('/v1/admin/wallets/' . Uuid::uuid7()->toString() . '/freeze', [
                'reason'     => 'Unauthorized freeze attempt',
                'checker_id' => Uuid::uuid7()->toString(),
                'hold_type'  => 'risk_freeze',
            ]);

        $response->assertStatus(403);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Seed a CUSTOMER user record directly into the identity schema.
     */
    private function seedCustomer(string $status = 'active'): string
    {
        $userId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'            => $userId,
            'email'         => 'qs-customer-' . $userId . '@test.local',
            'mobile_number' => '+2677' . mt_rand(1000000, 9999999),
            'status'        => $status,
            'actor_type'    => 'CUSTOMER',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return $userId;
    }
}
