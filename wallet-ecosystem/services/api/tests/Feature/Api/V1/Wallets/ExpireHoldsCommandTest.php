<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T156 — wallet:expire-holds command test.
 */
final class ExpireHoldsCommandTest extends WalletTestCase
{
    public function test_expire_holds_marks_expired_holds_and_sets_expired_at(): void
    {
        $userId   = (string) Uuid::uuid7();
        $walletId = $this->createWallet($userId, 'active');
        $pocketId = $this->createPocket($walletId, 'BWP');

        // Create a hold with expires_at in the past
        $holdId = $this->createHold($pocketId, 100, 'active', now()->subHour());

        // Run the command
        $this->artisan('wallet:expire-holds')->assertExitCode(0);

        // Hold must now be expired
        $hold = \DB::connection('wallet')
            ->table('wallet.wallet_holds')
            ->where('id', $holdId)
            ->first();

        $this->assertSame('expired', $hold->status);
        $this->assertNotNull($hold->expired_at);
    }

    public function test_expire_holds_is_idempotent_on_rerun(): void
    {
        $userId   = (string) Uuid::uuid7();
        $walletId = $this->createWallet($userId, 'active');
        $pocketId = $this->createPocket($walletId, 'BWP');

        $holdId = $this->createHold($pocketId, 50, 'active', now()->subHour());

        $this->artisan('wallet:expire-holds')->assertExitCode(0);
        $this->artisan('wallet:expire-holds')->assertExitCode(0);

        // Still exactly one expired hold
        $count = \DB::connection('wallet')
            ->table('wallet.wallet_holds')
            ->where('pocket_id', $pocketId)
            ->where('status', 'expired')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_expire_holds_dry_run_does_not_change_status(): void
    {
        $userId   = (string) Uuid::uuid7();
        $walletId = $this->createWallet($userId, 'active');
        $pocketId = $this->createPocket($walletId, 'BWP');

        $holdId = $this->createHold($pocketId, 50, 'active', now()->subHour());

        $this->artisan('wallet:expire-holds --dry-run')->assertExitCode(0);

        $hold = \DB::connection('wallet')
            ->table('wallet.wallet_holds')
            ->where('id', $holdId)
            ->first();

        $this->assertSame('active', $hold->status, 'Dry run must not expire holds');
    }

    public function test_expire_holds_does_not_touch_non_expired_holds(): void
    {
        $userId   = (string) Uuid::uuid7();
        $walletId = $this->createWallet($userId, 'active');
        $pocketId = $this->createPocket($walletId, 'BWP');

        // Future expiry — must not be touched
        $futureHoldId = $this->createHold($pocketId, 75, 'active', now()->addHour());

        $this->artisan('wallet:expire-holds')->assertExitCode(0);

        $hold = \DB::connection('wallet')
            ->table('wallet.wallet_holds')
            ->where('id', $futureHoldId)
            ->first();

        $this->assertSame('active', $hold->status, 'Non-expired holds must remain active');
    }
}
