<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use App\Modules\Wallet\Domain\Enums\WalletLimitType;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T086 + T118 — US-WALLET-006: Wallet limits + is_tbd behaviour.
 */
final class WalletLimitsTest extends WalletTestCase
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

        // Seed baseline TBD limit rows
        foreach (WalletLimitType::cases() as $type) {
            \DB::connection('wallet')->table('wallet.wallet_limits')->insert([
                'id'            => (string) Uuid::uuid7(),
                'wallet_id'     => $this->walletId,
                'currency_code' => 'BWP',
                'limit_type'    => $type->value,
                'limit_value'   => null,
                'is_tbd'        => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    /** Scenario 1: Admin reads limits — all seeded as is_tbd=true initially. */
    public function test_admin_can_read_wallet_limits(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/limits',
            $this->adminHeaders($this->adminId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['data' => [['limit_type', 'limit_value', 'is_tbd']]]);
    }

    /** Scenario 2: Admin sets a limit value; it takes effect. */
    public function test_admin_can_set_limit_value(): void
    {
        $response = $this->putJson('/v1/wallets/' . $this->walletId . '/limits', [
            'limits' => [
                [
                    'currency_code' => 'BWP',
                    'limit_type'    => 'daily_transaction_amount',
                    'limit_value'   => 500000,
                ],
            ],
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(200);

        $row = \DB::connection('wallet')->table('wallet.wallet_limits')
            ->where('wallet_id', $this->walletId)
            ->where('limit_type', 'daily_transaction_amount')
            ->first();

        $this->assertSame(500000, (int) $row->limit_value);
    }

    /** Scenario 3: Invalid limit value (negative) returns 422. */
    public function test_invalid_limit_value_returns_422(): void
    {
        $response = $this->putJson('/v1/wallets/' . $this->walletId . '/limits', [
            'limits' => [
                ['currency_code' => 'BWP', 'limit_type' => 'max_balance', 'limit_value' => -1],
            ],
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(422);
    }

    /** Scenario 4: Frozen wallet limits cannot be updated. */
    public function test_frozen_wallet_limits_update_returns_422(): void
    {
        $frozenId = $this->createWallet($this->userId, 'frozen', (string) Uuid::uuid7());

        $response = $this->putJson('/v1/wallets/' . $frozenId . '/limits', [
            'limits' => [
                ['currency_code' => 'BWP', 'limit_type' => 'max_balance', 'limit_value' => 1000],
            ],
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(422);
    }

    /** Scenario 5: Customer cannot manage limits (403). */
    public function test_customer_cannot_manage_limits(): void
    {
        $response = $this->putJson('/v1/wallets/' . $this->walletId . '/limits', [
            'limits' => [
                ['currency_code' => 'BWP', 'limit_type' => 'max_balance', 'limit_value' => 1000],
            ],
        ], array_merge($this->customerHeaders($this->userId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(403);
    }

    /**
     * T118 — is_tbd=true limits are not enforced (treated as unlimited).
     * Fresh deployment with all TBD limits → any debit passes enforcement.
     * (Unit test in WalletLimitService; here we confirm the API read shows is_tbd=true.)
     */
    public function test_tbd_limits_show_is_tbd_true_in_response(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/limits',
            $this->adminHeaders($this->adminId),
        );

        $response->assertStatus(200);

        $limits = collect($response->json('data'));
        $tbdLimits = $limits->where('is_tbd', true);

        // All seeded limits are TBD
        $this->assertGreaterThan(0, $tbdLimits->count());
        $tbdLimits->each(fn ($l) => $this->assertNull($l['limit_value']));
    }
}
