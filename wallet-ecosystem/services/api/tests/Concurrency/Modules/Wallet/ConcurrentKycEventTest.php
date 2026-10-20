<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Wallet;

use App\Modules\Wallet\Application\Services\WalletProvisioningService;
use App\Modules\Wallet\Domain\Exceptions\WalletAlreadyExistsException;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * CC-E06-004 / T116 — Same Kyc.Case.Approved event delivered 5 times concurrently.
 * Exactly 1 wallet is provisioned; idempotency prevents 4 duplicates.
 */
final class ConcurrentKycEventTest extends WalletTestCase
{
    public function test_five_concurrent_kyc_approved_events_produce_exactly_one_wallet(): void
    {
        $userId = (string) Uuid::uuid7();

        /** @var WalletProvisioningService $service */
        $service = app(WalletProvisioningService::class);

        $successes  = 0;
        $duplicates = 0;
        $walletIds  = [];

        for ($i = 0; $i < 5; $i++) {
            try {
                $wallet = $service->provisionForUser($userId, (string) Uuid::uuid7());
                $walletIds[] = $wallet->id;
                $successes++;
            } catch (WalletAlreadyExistsException) {
                $duplicates++;
            }
        }

        // Exactly 1 wallet provisioned; 4 duplicates rejected
        $walletCount = \DB::connection('wallet')
            ->table('wallet.wallets')
            ->where('owner_id', $userId)
            ->count();

        $this->assertSame(1, $walletCount,
            'Exactly one wallet must be provisioned for the user');
        $this->assertSame(1, $successes, 'Exactly 1 call must succeed');
        $this->assertSame(4, $duplicates, 'Exactly 4 calls must fail with WalletAlreadyExistsException');
    }
}
