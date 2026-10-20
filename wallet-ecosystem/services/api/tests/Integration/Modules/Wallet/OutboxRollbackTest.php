<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Wallet;

use App\Modules\Wallet\Application\Services\WalletProvisioningService;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T163 — Outbox event is rolled back if the DB transaction rolls back.
 *
 * Verifies that OutboxEventPublisher is called INSIDE the transaction boundary
 * for wallet provisioning, so a rollback removes both the wallet row and the outbox row.
 */
final class OutboxRollbackTest extends WalletTestCase
{
    public function test_outbox_event_rolled_back_with_transaction(): void
    {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        // Simulate a rollback by catching an injected failure after wallet creation
        $walletCreated  = null;
        $outboxRowsBefore = \DB::table('platform.outbox_events')->count();

        try {
            \DB::transaction(function () use ($userId, $correlationId, &$walletCreated): void {
                /** @var WalletProvisioningService $service */
                $service = app(WalletProvisioningService::class);
                $walletCreated = $service->provisionForUser($userId, $correlationId);

                // Force rollback by throwing inside the transaction
                throw new \RuntimeException('Simulated rollback');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        // Wallet row must not exist in DB
        $walletCount = \DB::connection('wallet')
            ->table('wallet.wallets')
            ->where('owner_id', $userId)
            ->count();

        $this->assertSame(0, $walletCount, 'Wallet row must be rolled back with the transaction');

        // Outbox event count must not have increased
        $outboxRowsAfter = \DB::table('platform.outbox_events')->count();
        $this->assertSame($outboxRowsBefore, $outboxRowsAfter,
            'Outbox event must be rolled back with the transaction');
    }
}
