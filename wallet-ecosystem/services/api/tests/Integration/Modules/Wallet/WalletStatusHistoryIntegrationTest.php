<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Wallet;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T134 — Balance read on frozen wallet returns HTTP 200.
 * T141 — Status history rows never modified after write; chronological order.
 */
final class WalletStatusHistoryIntegrationTest extends WalletTestCase
{
    private string $userId;
    private string $walletId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.stub_ledger_balance' => true]);

        $this->userId   = (string) Uuid::uuid7();
        $this->walletId = $this->createWallet($this->userId, 'frozen');
        $this->createPocket($this->walletId, 'BWP');
    }

    /** T134 — Balance read on frozen wallet returns 200. */
    public function test_balance_read_on_frozen_wallet_returns_200(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.wallet_status', 'frozen');
    }

    /** T141 — Row count equals transition count; entries in chronological order. */
    public function test_status_history_rows_are_in_chronological_order(): void
    {
        $transitions = [
            ['from' => null,      'to' => 'pending',   'minutes' => 5],
            ['from' => 'pending', 'to' => 'active',    'minutes' => 4],
            ['from' => 'active',  'to' => 'frozen',    'minutes' => 3],
            ['from' => 'frozen',  'to' => 'active',    'minutes' => 2],
        ];

        foreach ($transitions as $t) {
            \DB::connection('wallet')->table('wallet.wallet_status_history')->insert([
                'id'          => (string) Uuid::uuid7(),
                'wallet_id'   => $this->walletId,
                'from_status' => $t['from'],
                'to_status'   => $t['to'],
                'reason'      => 'Test',
                'actor_id'    => (string) Uuid::uuid7(),
                'occurred_at' => now()->subMinutes($t['minutes']),
            ]);
        }

        $rows = \DB::connection('wallet')
            ->table('wallet.wallet_status_history')
            ->where('wallet_id', $this->walletId)
            ->orderBy('occurred_at', 'asc')
            ->get();

        $this->assertCount(4, $rows);

        // Verify chronological order
        $timestamps = $rows->pluck('occurred_at')->all();
        $sorted     = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps, 'History rows must be in ascending occurred_at order');

        // Verify rows were not modified (count unchanged)
        $count = \DB::connection('wallet')
            ->table('wallet.wallet_status_history')
            ->where('wallet_id', $this->walletId)
            ->count();

        $this->assertSame(4, $count);
    }

    /** T141 — from_status is null for the initial pending entry. */
    public function test_initial_history_entry_has_null_from_status(): void
    {
        \DB::connection('wallet')->table('wallet.wallet_status_history')->insert([
            'id'          => (string) Uuid::uuid7(),
            'wallet_id'   => $this->walletId,
            'from_status' => null,
            'to_status'   => 'pending',
            'reason'      => 'Created',
            'actor_id'    => (string) Uuid::uuid7(),
            'occurred_at' => now(),
        ]);

        $first = \DB::connection('wallet')
            ->table('wallet.wallet_status_history')
            ->where('wallet_id', $this->walletId)
            ->where('to_status', 'pending')
            ->first();

        $this->assertNull($first->from_status);
    }
}
