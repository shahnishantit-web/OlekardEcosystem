<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US1 — Recipient Lookup.
 *
 * Tests the POST /api/v1/transfers/recipient-lookup endpoint covering:
 * - Happy path by phone number
 * - Happy path by wallet_number
 * - Recipient not found
 * - Recipient wallet inactive
 * - Self-transfer rejected
 * - Validation: both inputs provided
 * - Validation: neither input provided
 */
final class RecipientLookupTest extends TransferTestCase
{
    private object $senderActor;

    protected function setUp(): void
    {
        parent::setUp();

        // Create authenticated actor that matches the seeded senderCustomerId
        $this->senderActor = $this->makeActorForCustomer($this->senderCustomerId);
    }

    public function test_lookup_by_phone_returns_token_and_display_info(): void
    {
        // Phone number must match the recipient row seeded in TransferTestCase
        $recipientMobile = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $this->recipientCustomerId)
            ->value('mobile_number');

        $response = $this->withActorToken($this->senderActor)
            ->postJson('/api/v1/transfers/recipient-lookup', [
                'phone_number' => $recipientMobile,
                'currency_code' => $this->currencyCode,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['lookup_token', 'display_name', 'masked_mobile']])
            ->assertJsonPath('data.masked_mobile', function ($masked) use ($recipientMobile) {
                return str_contains($masked, '*');
            });
    }

    public function test_lookup_by_wallet_id_returns_token(): void
    {
        $response = $this->withActorToken($this->senderActor)
            ->postJson('/api/v1/transfers/recipient-lookup', [
                'wallet_number' => $this->recipientWalletId,
                'currency_code' => $this->currencyCode,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['lookup_token', 'display_name', 'masked_mobile']]);
    }

    public function test_unknown_phone_returns_404(): void
    {
        $this->withActorToken($this->senderActor)
            ->postJson('/api/v1/transfers/recipient-lookup', [
                'phone_number'  => '+26799000000',
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RECIPIENT_NOT_FOUND');
    }

    public function test_inactive_wallet_returns_422(): void
    {
        // Suspend the recipient's wallet
        DB::connection('platform')->table('wallet.wallets')
            ->where('id', $this->recipientWalletId)
            ->update(['status' => 'suspended']);

        $this->withActorToken($this->senderActor)
            ->postJson('/api/v1/transfers/recipient-lookup', [
                'wallet_number' => $this->recipientWalletId,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RECIPIENT_WALLET_INACTIVE');
    }

    public function test_self_transfer_returns_422(): void
    {
        // Use own wallet as recipient
        $this->withActorToken($this->senderActor)
            ->postJson('/api/v1/transfers/recipient-lookup', [
                'wallet_number' => $this->senderWalletId,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SELF_TRANSFER_NOT_ALLOWED');
    }

    public function test_both_inputs_fails_validation(): void
    {
        $this->withActorToken($this->senderActor)
            ->postJson('/api/v1/transfers/recipient-lookup', [
                'phone_number'  => '+26771111111',
                'wallet_number' => $this->recipientWalletId,
                'currency_code' => $this->currencyCode,
            ])
            ->assertStatus(422);
    }

    public function test_neither_input_fails_validation(): void
    {
        $this->withActorToken($this->senderActor)
            ->postJson('/api/v1/transfers/recipient-lookup', [
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
            'slug'           => 'test-transfer-role-' . substr($roleId, 0, 8),
            'display_name'   => 'Test Role',
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

        return (object) [
            'id'         => $customerId,
            'actor_type' => 'CUSTOMER',
        ];
    }
}
