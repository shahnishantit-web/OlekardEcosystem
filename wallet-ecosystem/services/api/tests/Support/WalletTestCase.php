<?php

declare(strict_types=1);

namespace Tests\Support;

use Ramsey\Uuid\Uuid;

/**
 * Base test case for E06 Wallet module feature, security, and integration tests.
 *
 * Wraps both `null` (platform) and `wallet` connections in DB transactions per test.
 * Registers FakeIdentityAuthMiddleware to bypass real JWT validation.
 */
abstract class WalletTestCase extends TestCase
{
    /** @var string[] */
    protected $connectionsToTransact = [null, 'wallet'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    }

    // ── Auth header helpers ──────────────────────────────────────────────────

    protected function adminHeaders(string $adminId, array $extra = []): array
    {
        return array_merge([
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'ADMIN',
            'X-Test-Permissions' => implode(',', [
                'wallet.create', 'wallet.read', 'wallet.read.any',
                'wallet.status.manage', 'wallet.freeze', 'wallet.unfreeze',
                'wallet.close', 'wallet.limits.manage', 'wallet.pocket.create',
                'wallet.balance.read',
            ]),
        ], $extra);
    }

    protected function customerHeaders(string $userId, array $extra = []): array
    {
        return array_merge([
            'X-Test-User-Id'     => $userId,
            'X-Test-Actor-Type'  => 'CUSTOMER',
            'X-Test-Permissions' => implode(',', [
                'wallet.read', 'wallet.pocket.create', 'wallet.balance.read',
            ]),
        ], $extra);
    }

    protected function noAuthHeaders(): array
    {
        return [];
    }

    // ── DB fixture helpers ───────────────────────────────────────────────────

    protected function createWallet(
        string $ownerId,
        string $status = 'active',
        ?string $walletId = null,
    ): string {
        $id = $walletId ?? (string) Uuid::uuid7();
        \DB::connection('wallet')->table('wallet.wallets')->insert([
            'id'               => $id,
            'wallet_reference' => 'WLT-' . date('Ymd') . '-' . strtoupper(substr(md5($id), 0, 8)),
            'owner_id'         => $ownerId,
            'status'           => $status,
            'version'          => 1,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        return $id;
    }

    protected function createPocket(
        string $walletId,
        string $currencyCode = 'BWP',
        string $status = 'active',
        ?string $pocketId = null,
    ): string {
        $id = $pocketId ?? (string) Uuid::uuid7();
        \DB::connection('wallet')->table('wallet.currency_pockets')->insert([
            'id'                       => $id,
            'wallet_id'                => $walletId,
            'currency_code'            => $currencyCode,
            'status'                   => $status,
            'ledger_account_reference' => 'ledger-' . $id,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
        return $id;
    }

    protected function createHold(
        string $pocketId,
        int $amount,
        string $holdStatus = 'active',
        ?\DateTimeInterface $expiresAt = null,
        ?string $holdId = null,
    ): string {
        $id = $holdId ?? (string) Uuid::uuid7();
        \DB::connection('wallet')->table('wallet.wallet_holds')->insert([
            'id'             => $id,
            'pocket_id'      => $pocketId,
            'amount'         => $amount,
            'status'         => $holdStatus,
            'correlation_id' => (string) Uuid::uuid7(),
            'expires_at'     => $expiresAt,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        return $id;
    }

    protected function createStatusHistory(
        string $walletId,
        string $fromStatus,
        string $toStatus,
        string $actorId,
    ): void {
        \DB::connection('wallet')->table('wallet.wallet_status_history')->insert([
            'id'          => (string) Uuid::uuid7(),
            'wallet_id'   => $walletId,
            'from_status' => $fromStatus,
            'to_status'   => $toStatus,
            'reason'      => 'Test transition',
            'actor_id'    => $actorId,
            'occurred_at' => now(),
        ]);
    }

    protected function idempotencyKey(): string
    {
        return (string) Uuid::uuid7();
    }
}
