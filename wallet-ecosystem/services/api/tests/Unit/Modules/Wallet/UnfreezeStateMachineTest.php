<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Exceptions\InvalidWalletTransitionException;
use App\Modules\Wallet\Domain\Services\WalletStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * T130 — WalletStateMachine: Frozen→Active and Active→Active (invalid unfreeze).
 */
final class UnfreezeStateMachineTest extends TestCase
{
    private WalletStateMachine $sm;

    protected function setUp(): void
    {
        $this->sm = new WalletStateMachine();
    }

    public function test_frozen_to_active_is_valid_transition(): void
    {
        $this->sm->transition(WalletStatus::Frozen, WalletStatus::Active);
        $this->assertTrue(true);
    }

    public function test_active_to_active_throws_invalid_transition(): void
    {
        $this->expectException(InvalidWalletTransitionException::class);
        $this->sm->transition(WalletStatus::Active, WalletStatus::Active);
    }

    public function test_suspended_to_active_is_valid(): void
    {
        $this->sm->transition(WalletStatus::Suspended, WalletStatus::Active);
        $this->assertTrue(true);
    }

    public function test_restricted_to_active_is_valid(): void
    {
        $this->sm->transition(WalletStatus::Restricted, WalletStatus::Active);
        $this->assertTrue(true);
    }

    public function test_pending_to_active_is_valid(): void
    {
        $this->sm->transition(WalletStatus::Pending, WalletStatus::Active);
        $this->assertTrue(true);
    }
}
