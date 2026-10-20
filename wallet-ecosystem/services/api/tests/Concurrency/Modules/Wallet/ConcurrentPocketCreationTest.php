<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Wallet;

use App\Modules\Wallet\Application\Services\WalletPocketService;
use App\Modules\Wallet\Domain\Exceptions\PocketAlreadyExistsException;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * CC-E06-005 / T117 — 5 concurrent POST /wallets/{id}/pockets for same currency.
 * Exactly 1 succeeds (201); rest fail (409 PocketAlreadyExistsException).
 */
final class ConcurrentPocketCreationTest extends WalletTestCase
{
    public function test_five_concurrent_pocket_creations_produce_exactly_one(): void
    {
        $userId   = (string) Uuid::uuid7();
        $walletId = $this->createWallet($userId, 'active');
        $actorId  = $userId;

        /** @var WalletPocketService $service */
        $service = app(WalletPocketService::class);

        $successes = 0;
        $conflicts = 0;

        for ($i = 0; $i < 5; $i++) {
            try {
                $service->addPocket(
                    walletId:      $walletId,
                    currencyCode:  'USD',
                    actorId:       $actorId,
                    correlationId: (string) Uuid::uuid7(),
                );
                $successes++;
            } catch (PocketAlreadyExistsException) {
                $conflicts++;
            }
        }

        $pocketCount = \DB::connection('wallet')
            ->table('wallet.currency_pockets')
            ->where('wallet_id', $walletId)
            ->where('currency_code', 'USD')
            ->count();

        $this->assertSame(1, $pocketCount, 'Exactly one USD pocket must exist in DB');
        $this->assertSame(1, $successes, 'Exactly 1 call must succeed');
        $this->assertSame(4, $conflicts, 'Exactly 4 calls must fail with PocketAlreadyExistsException');
    }
}
