<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Wallet;

use App\Modules\Wallet\Application\Services\WalletProvisioningService;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T052 — US-WALLET-002: No duplicate wallet_reference under concurrent provisioning.
 *
 * Creates 50 wallets sequentially and asserts all wallet_references are unique.
 * True concurrency test (separate processes) requires real PostgreSQL and is
 * exercised in T106/T116 concurrency tests. This test validates uniqueness under
 * serial load using real DB constraints.
 */
final class WalletReferenceUniquenessTest extends WalletTestCase
{
    public function test_fifty_wallets_have_distinct_wallet_references(): void
    {
        /** @var WalletProvisioningService $service */
        $service = app(WalletProvisioningService::class);

        $references = [];

        for ($i = 0; $i < 50; $i++) {
            $userId       = (string) Uuid::uuid7();
            $correlationId = (string) Uuid::uuid7();

            $wallet = $service->provisionForUser($userId, $correlationId);
            $references[] = $wallet->wallet_reference;
        }

        $this->assertSame(50, count(array_unique($references)),
            'All 50 wallet_references must be distinct.');
    }
}
