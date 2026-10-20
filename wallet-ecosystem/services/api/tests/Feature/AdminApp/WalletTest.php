<?php

declare(strict_types=1);

namespace Tests\Feature\AdminApp;

use Tests\TestCase;

final class WalletTest extends TestCase
{
    public function test_wallet_search_returns_paginated_results(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.wallets.read')
            ->getJson('/v1/admin-app/wallets?per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['wallet_id', 'wallet_number', 'status', 'balance_minor_units']],
                'meta' => ['total', 'per_page'],
            ]);
    }

    public function test_wallet_search_can_be_scoped_to_one_user(): void
    {
        $userId = (string) \Illuminate\Support\Str::uuid();
        $walletId = (string) \Illuminate\Support\Str::uuid();
        $pocketId = (string) \Illuminate\Support\Str::uuid();
        $now = now();

        \Illuminate\Support\Facades\DB::connection('identity')->table('identity.users')->insert([
            'id' => $userId,
            'actor_type' => 'CUSTOMER',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        \Illuminate\Support\Facades\DB::connection('wallet')->table('wallet.wallets')->insert([
            'id' => $walletId,
            'wallet_reference' => 'WLT-QA-' . strtoupper(substr(str_replace('-', '', $walletId), 0, 8)),
            'owner_id' => $userId,
            'status' => 'active',
            'version' => 0,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        \Illuminate\Support\Facades\DB::connection('wallet')->table('wallet.currency_pockets')->insert([
            'id' => $pocketId,
            'wallet_id' => $walletId,
            'currency_code' => 'BWP',
            'ledger_account_reference' => 'qa-wallet-' . $pocketId,
            'status' => 'active',
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->actingAsAdminWithPermission('adminapp.wallets.read')
            ->getJson('/v1/admin-app/wallets?user_id=' . $userId . '&per_page=10');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $walletId)
            ->assertJsonPath('data.0.user_id', $userId)
            ->assertJsonPath('data.0.currency', 'BWP')
            ->assertJsonPath('data.0.balance_minor_units', 0);
    }

    public function test_wallet_detail_reads_the_wallet_module_schema(): void
    {
        [$userId, $walletId] = $this->createWalletReadFixture();

        $this->actingAsAdminWithPermission('adminapp.wallets.read')
            ->getJson("/v1/admin-app/wallets/{$walletId}")
            ->assertOk()
            ->assertJsonPath('data.id', $walletId)
            ->assertJsonPath('data.user_id', $userId)
            ->assertJsonPath('data.currency', 'BWP')
            ->assertJsonPath('data.balance_minor_units', 0);
    }

    public function test_wallet_ledger_route_uses_the_wallet_read_permission_and_returns_empty_history(): void
    {
        [, $walletId] = $this->createWalletReadFixture();

        $this->actingAsAdminWithPermission('adminapp.wallets.read')
            ->getJson("/v1/admin-app/wallets/{$walletId}/ledger?currency=BWP")
            ->assertOk()
            ->assertJsonPath('data.data', [])
            ->assertJsonPath('data.meta.total', 0);

        $this->actingAsAdminWithPermission('adminapp.users.read')
            ->getJson("/v1/admin-app/wallets/{$walletId}/ledger?currency=BWP")
            ->assertForbidden();
    }

    public function test_freeze_wallet_returns_202(): void
    {
        $walletId = $this->createActiveWallet();

        $response = $this->actingAsAdminWithPermission('adminapp.wallets.freeze')
            ->withHeaders(['Idempotency-Key' => 'freeze-wallet-' . $walletId])
            ->postJson("/v1/admin-app/wallets/{$walletId}/freeze", [
                'reason' => 'Suspected fraud',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.action_type', 'adminapp.wallet.freeze')
            ->assertJsonPath('data.status', 'pending_approval');
    }

    public function test_unfreeze_non_frozen_wallet_returns_422(): void
    {
        $walletId = $this->createActiveWallet();

        $response = $this->actingAsAdminWithPermission('adminapp.wallets.unfreeze')
            ->withHeaders(['Idempotency-Key' => 'unfreeze-active-wallet-' . $walletId])
            ->postJson("/v1/admin-app/wallets/{$walletId}/unfreeze", [
                'reason' => 'Investigation complete',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'ADMINAPP_WALLET_002');
    }

    public function test_compliance_role_denied_freeze(): void
    {
        $walletId = $this->createActiveWallet();

        $response = $this->actingAsAdminWithPermission('adminapp.kyc.review')
            ->withHeaders(['Idempotency-Key' => 'compliance-freeze-' . $walletId])
            ->postJson("/v1/admin-app/wallets/{$walletId}/freeze", ['reason' => 'Test']);

        $response->assertStatus(403);
    }

    private function createActiveWallet(): string
    {
        return $this->createWalletReadFixture()[1];
    }

    /** @return array{string, string} */
    private function createWalletReadFixture(): array
    {
        $userId = (string) \Illuminate\Support\Str::uuid();
        $walletId = (string) \Illuminate\Support\Str::uuid();
        $pocketId = (string) \Illuminate\Support\Str::uuid();
        $now = now();

        \Illuminate\Support\Facades\DB::connection('identity')->table('identity.users')->insert([
            'id' => $userId, 'actor_type' => 'CUSTOMER', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        \Illuminate\Support\Facades\DB::connection('wallet')->table('wallet.wallets')->insert([
            'id' => $walletId,
            'wallet_reference' => 'WLT-QA-' . strtoupper(substr(str_replace('-', '', $walletId), 0, 8)),
            'owner_id' => $userId, 'status' => 'active', 'version' => 0, 'created_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        \Illuminate\Support\Facades\DB::connection('wallet')->table('wallet.currency_pockets')->insert([
            'id' => $pocketId, 'wallet_id' => $walletId, 'currency_code' => 'BWP',
            'ledger_account_reference' => 'qa-wallet-' . $pocketId, 'status' => 'active',
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$userId, $walletId];
    }
}
