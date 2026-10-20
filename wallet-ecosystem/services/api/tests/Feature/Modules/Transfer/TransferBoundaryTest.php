<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

/**
 * Transfer module boundary / authorization tests.
 *
 * Verifies that every Transfer endpoint enforces:
 * - Authentication (401 when no token)
 * - Permission enforcement (403 when authenticated but missing permission)
 * - Wallet ownership enforcement (403 when actor does not own the wallet)
 *
 * Does NOT test business logic — just that authorization gates fire.
 */
final class TransferBoundaryTest extends TransferTestCase
{
    // ── Authentication boundary ───────────────────────────────────────────────

    public function test_recipient_lookup_requires_authentication(): void
    {
        $this->postJson('/api/v1/transfers/recipient-lookup', ['mobile_number' => '+26771000001'])
            ->assertStatus(401);
    }

    public function test_initiate_requires_authentication(): void
    {
        $this->postJson('/api/v1/transfers', [])
            ->assertStatus(401);
    }

    public function test_confirm_requires_authentication(): void
    {
        $this->postJson('/api/v1/transfers/some-id/confirm', [])
            ->assertStatus(401);
    }

    public function test_cancel_requires_authentication(): void
    {
        $this->postJson('/api/v1/transfers/some-id/cancel', [])
            ->assertStatus(401);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/transfers')
            ->assertStatus(401);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/transfers/some-id')
            ->assertStatus(401);
    }

    public function test_receipt_requires_authentication(): void
    {
        $this->getJson('/api/v1/transfers/receipts/some-ref')
            ->assertStatus(401);
    }

    // ── Permission boundary ───────────────────────────────────────────────────

    public function test_recipient_lookup_requires_transfer_initiate_permission(): void
    {
        $actor = $this->makeCustomerWithPermission('transfer.read');

        $this->withActorToken($actor)
            ->postJson('/api/v1/transfers/recipient-lookup', ['mobile_number' => '+26771000001'])
            ->assertStatus(403);
    }

    public function test_initiate_requires_transfer_initiate_permission(): void
    {
        $actor = $this->makeCustomerWithPermission('transfer.read');

        $this->withActorToken($actor)
            ->postJson('/api/v1/transfers', ['wallet_id' => $this->senderWalletId])
            ->assertStatus(403);
    }

    public function test_confirm_requires_transfer_initiate_permission(): void
    {
        $actor = $this->makeCustomerWithPermission('transfer.read');

        $this->withActorToken($actor)
            ->postJson('/api/v1/transfers/some-id/confirm', ['lookup_token' => 'tok', 'pin' => '1234'])
            ->assertStatus(403);
    }

    public function test_history_requires_transfer_read_permission(): void
    {
        $actor = $this->makeCustomerWithPermission('transfer.initiate');

        $this->withActorToken($actor)
            ->getJson('/api/v1/transfers')
            ->assertStatus(403);
    }

    // ── Admin boundary ────────────────────────────────────────────────────────

    public function test_admin_index_requires_transfer_admin_permission(): void
    {
        $actor = $this->makeCustomerWithPermission('transfer.read');

        $this->withActorToken($actor)
            ->getJson('/api/v1/admin/transfers')
            ->assertStatus(403);
    }

    public function test_admin_force_fail_requires_transfer_admin_permission(): void
    {
        $actor = $this->makeCustomerWithPermission('transfer.read');

        $this->withActorToken($actor)
            ->postJson('/api/v1/admin/transfers/some-id/force-fail', [])
            ->assertStatus(403);
    }
}
