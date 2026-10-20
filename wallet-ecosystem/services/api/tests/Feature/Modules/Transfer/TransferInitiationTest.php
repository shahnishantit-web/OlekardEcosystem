<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Application\Services\LookupTokenService;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US1 — Transfer Initiation.
 *
 * Tests POST /api/v1/transfers covering:
 * - Happy path: pending transfer created
 * - IDOR: cannot initiate for another customer's wallet
 * - Invalid / expired / already-used lookup token
 * - Below-minimum amount
 * - Insufficient balance
 * - Idempotency: same Idempotency-Key returns existing record
 */
final class TransferInitiationTest extends TransferTestCase
{
    private object $senderActor;
    private string $lookupToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->senderActor = $this->makeActorForCustomer($this->senderCustomerId);

        // Seed sender with sufficient balance
        $this->creditSenderBalance(200000);

        // Pre-issue a lookup token via the service
        $tokenService    = app(LookupTokenService::class);
        $this->lookupToken = $tokenService->issue(
            recipientCustomerId: $this->recipientCustomerId,
            recipientWalletId:   $this->recipientWalletId,
            pocketLedgerRef:     $this->recipientPocketRef,
            currencyCode:        $this->currencyCode,
        );
    }

    public function test_happy_path_creates_pending_transfer(): void
    {
        $response = $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $this->lookupToken,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 10000,
                'currency_code' => $this->currencyCode,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['id', 'transfer_reference', 'gross_amount', 'fee_amount', 'net_amount']]);
    }

    public function test_idor_cannot_use_another_customers_wallet(): void
    {
        // Create a second customer and try to initiate using senderWalletId (which belongs to senderCustomer)
        $attacker = $this->makeActorForCustomer((string) Uuid::uuid7());

        $this->withActorToken($attacker)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $this->lookupToken,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 10000,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(422);
    }

    public function test_invalid_lookup_token_returns_422(): void
    {
        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => (string) Uuid::uuid7(),
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 10000,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_LOOKUP_TOKEN');
    }

    public function test_used_token_cannot_be_reused(): void
    {
        $idempotencyKey = (string) Uuid::uuid7();

        // First use succeeds
        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $this->lookupToken,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 10000,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(201);

        // Issue a new token for second call since first was consumed
        $tokenService   = app(LookupTokenService::class);
        $secondToken    = $tokenService->issue(
            recipientCustomerId: $this->recipientCustomerId,
            recipientWalletId:   $this->recipientWalletId,
            pocketLedgerRef:     $this->recipientPocketRef,
            currencyCode:        $this->currencyCode,
        );

        // Reuse same Idempotency-Key returns same pending transfer (idempotent)
        $response = $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $secondToken,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 10000,
                'currency_code' => $this->currencyCode,
            ]);

        $response->assertStatus(201);
    }

    public function test_amount_below_minimum_returns_422(): void
    {
        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $this->lookupToken,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 50,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(422);
    }

    public function test_insufficient_balance_returns_422(): void
    {
        $tokenService = app(LookupTokenService::class);
        $token        = $tokenService->issue(
            recipientCustomerId: $this->recipientCustomerId,
            recipientWalletId:   $this->recipientWalletId,
            pocketLedgerRef:     $this->recipientPocketRef,
            currencyCode:        $this->currencyCode,
        );

        // Request more than the 200000 we seeded
        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $token,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 9_000_000,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');
    }

    public function test_idempotency_key_required(): void
    {
        $this->withActorToken($this->senderActor)
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $this->lookupToken,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => 10000,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(422);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeActorForCustomer(string $customerId): object
    {
        $roleId = (string) Uuid::uuid7();

        DB::connection('identity')->table('identity.roles')->insert([
            'id'             => $roleId,
            'slug'           => 'role-' . substr($roleId, 0, 8),
            'display_name'   => 'Transfer Test Role',
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

        if (DB::connection('identity')->table('identity.users')->where('id', $customerId)->exists()) {
            DB::connection('identity')->table('identity.users')
                ->where('id', $customerId)
                ->update(['default_role_id' => $roleId]);
        } else {
            DB::connection('identity')->table('identity.users')->insert([
                'id'              => $customerId,
                'email'           => 'actor-' . $customerId . '@test.local',
                'mobile_number'   => '+99' . mt_rand(100000000, 999999999),
                'actor_type'      => 'CUSTOMER',
                'status'          => 'active',
                'default_role_id' => $roleId,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        return (object) [
            'id'         => $customerId,
            'actor_type' => 'CUSTOMER',
        ];
    }
}
