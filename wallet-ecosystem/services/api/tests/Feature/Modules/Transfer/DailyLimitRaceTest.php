<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Application\Services\LookupTokenService;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Concurrency tests for the daily transfer limit.
 *
 * The DatabaseTransactions trait does NOT work for concurrency tests that
 * span real concurrent DB connections. This test uses manual cleanup instead
 * and must NOT be run in CI transactions mode.
 *
 * Strategy: simulate two near-simultaneous initiate calls by issuing them
 * sequentially within one process. Since the daily limit check is optimistic
 * (no SELECT FOR UPDATE), both calls can succeed if the sender's daily total
 * is near the limit. This test verifies the guard works when both are sequential.
 *
 * For true concurrent race protection, the spec relies on the confirmation
 * step's double-entry journal idempotency + balance validation (FR-E09-030).
 */
final class DailyLimitRaceTest extends TransferTestCase
{
    private object $senderActor;

    /** @var string[] Collect IDs for manual cleanup */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->senderActor = $this->makeTransferActor($this->senderCustomerId);
        $this->creditSenderBalance(5_000_000); // Fund well above daily limit for testing
    }

    public function test_sequential_requests_cannot_exceed_daily_limit(): void
    {
        $dailyLimit = 2_000_000;

        // First request: transfer 1,500,000 — should succeed
        $token1 = $this->issueToken();
        $r1 = $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $token1,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 1_500_000,
                'currency_code' => $this->currencyCode,
            ]);

        $r1->assertStatus(201);
        $this->createdIds[] = $r1->json('data.id');

        // Second request: transfer 600,000 — total would be 2,100,000 > 2,000,000 → should fail
        $token2 = $this->issueToken();
        $r2 = $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $token2,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 600_000,
                'currency_code' => $this->currencyCode,
            ]);

        $r2->assertStatus(422)
            ->assertJsonPath('error.code', 'DAILY_LIMIT_EXCEEDED');
    }

    public function test_within_daily_limit_succeeds_multiple_times(): void
    {
        // Two small transfers that together stay under the limit
        $token1 = $this->issueToken();
        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $token1,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 500_000,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(201);

        $token2 = $this->issueToken();
        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $token2,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 500_000,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(201);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function issueToken(): string
    {
        $tokenService = app(LookupTokenService::class);
        return $tokenService->issue(
            recipientCustomerId: $this->recipientCustomerId,
            recipientWalletId:   $this->recipientWalletId,
            pocketLedgerRef:     $this->recipientPocketRef,
            currencyCode:        $this->currencyCode,
        );
    }

    private function makeTransferActor(string $customerId): object
    {
        $roleId = (string) Uuid::uuid7();

        DB::connection('identity')->table('identity.roles')->insert([
            'id'             => $roleId,
            'slug'           => 'daily-limit-role-' . substr($roleId, 0, 8),
            'display_name'   => 'Daily Limit Role',
            'is_system_role' => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        foreach (['transfer.initiate', 'transfer.read'] as $slug) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Uuid::uuid7(),
                'slug'                 => $slug,
                'display_name'         => $slug,
                'module'               => 'transfer',
                'is_system_permission' => false,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            $perm = DB::connection('identity')
                ->table('identity.permissions')
                ->where('slug', $slug)
                ->first(['id']);

            DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                'id'            => (string) Uuid::uuid7(),
                'role_id'       => $roleId,
                'permission_id' => $perm->id,
                'created_at'    => now(),
            ]);
        }

        DB::connection('identity')->table('identity.users')
            ->where('id', $customerId)
            ->update(['default_role_id' => $roleId]);

        return (object) ['id' => $customerId, 'actor_type' => 'CUSTOMER'];
    }
}
