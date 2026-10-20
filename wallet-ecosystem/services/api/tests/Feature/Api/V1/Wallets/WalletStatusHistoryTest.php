<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T140 — US-WALLET-017: Status history API.
 */
final class WalletStatusHistoryTest extends WalletTestCase
{
    private string $adminId;
    private string $userId;
    private string $walletId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId  = (string) Uuid::uuid7();
        $this->userId   = (string) Uuid::uuid7();
        $this->walletId = $this->createWallet($this->userId, 'active');
    }

    /** Scenario 1: 4-entry full lifecycle in order. */
    public function test_full_lifecycle_returns_four_entries_in_order(): void
    {
        // Create 4 history entries
        \DB::connection('wallet')->table('wallet.wallet_status_history')->insert([
            ['id' => (string) Uuid::uuid7(), 'wallet_id' => $this->walletId, 'from_status' => null,      'to_status' => 'pending',   'reason' => 'Created',    'actor_id' => $this->adminId, 'occurred_at' => now()->subMinutes(4)],
            ['id' => (string) Uuid::uuid7(), 'wallet_id' => $this->walletId, 'from_status' => 'pending', 'to_status' => 'active',    'reason' => 'Activated',  'actor_id' => $this->adminId, 'occurred_at' => now()->subMinutes(3)],
            ['id' => (string) Uuid::uuid7(), 'wallet_id' => $this->walletId, 'from_status' => 'active',  'to_status' => 'frozen',    'reason' => 'Frozen',     'actor_id' => $this->adminId, 'occurred_at' => now()->subMinutes(2)],
            ['id' => (string) Uuid::uuid7(), 'wallet_id' => $this->walletId, 'from_status' => 'frozen',  'to_status' => 'active',    'reason' => 'Unfrozen',   'actor_id' => $this->adminId, 'occurred_at' => now()->subMinutes(1)],
        ]);

        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/status-history',
            $this->adminHeaders($this->adminId),
        );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => [['id', 'from_status', 'to_status', 'reason', 'actor_id']]]);

        $entries = $response->json('data');
        $this->assertCount(4, $entries);
        $this->assertSame('pending', $entries[0]['to_status']);
        $this->assertSame('active', $entries[3]['to_status']);
    }

    /** Scenario 2: Actor ID is recorded correctly in history. */
    public function test_actor_id_is_recorded_in_history(): void
    {
        \DB::connection('wallet')->table('wallet.wallet_status_history')->insert([
            'id'          => (string) Uuid::uuid7(),
            'wallet_id'   => $this->walletId,
            'from_status' => 'active',
            'to_status'   => 'suspended',
            'reason'      => 'Test',
            'actor_id'    => $this->adminId,
            'occurred_at' => now(),
        ]);

        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/status-history',
            $this->adminHeaders($this->adminId),
        );

        $response->assertStatus(200);
        $entry = collect($response->json('data'))->firstWhere('to_status', 'suspended');
        $this->assertSame($this->adminId, $entry['actor_id']);
    }

    /** Scenario 3: Cursor pagination metadata is present. */
    public function test_cursor_pagination_metadata_present(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/status-history',
            $this->adminHeaders($this->adminId),
        );

        $response->assertStatus(200)
                 ->assertJsonStructure(['pagination' => ['next_cursor', 'has_more']]);
    }

    /** Scenario 4: IDOR — other user gets 404. */
    public function test_other_user_gets_404_idor(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/status-history',
            $this->customerHeaders((string) Uuid::uuid7()),
        );
        $response->assertStatus(404);
    }

    /** Scenario 5: Owner can read own status history. */
    public function test_owner_can_read_status_history(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/status-history',
            $this->customerHeaders($this->userId),
        );
        $response->assertStatus(200);
    }
}
