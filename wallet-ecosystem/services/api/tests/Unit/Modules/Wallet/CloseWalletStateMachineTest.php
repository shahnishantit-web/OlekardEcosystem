<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Exceptions\InvalidWalletTransitionException;
use App\Modules\Wallet\Domain\Services\WalletStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * T152 — WalletStateMachine close paths.
 */
final class CloseWalletStateMachineTest extends TestCase
{
    private WalletStateMachine $sm;

    protected function setUp(): void
    {
        $this->sm = new WalletStateMachine();
    }

    public function test_pending_to_closed_is_valid(): void
    {
        $this->sm->transition(WalletStatus::Pending, WalletStatus::Closed);
        $this->assertTrue(true);
    }

    public function test_active_to_closed_is_valid(): void
    {
        $this->sm->transition(WalletStatus::Active, WalletStatus::Closed);
        $this->assertTrue(true);
    }

    public function test_frozen_to_closed_is_valid(): void
    {
        $this->sm->transition(WalletStatus::Frozen, WalletStatus::Closed);
        $this->assertTrue(true);
    }

    public function test_suspended_to_closed_is_valid(): void
    {
        $this->sm->transition(WalletStatus::Suspended, WalletStatus::Closed);
        $this->assertTrue(true);
    }

    public function test_restricted_to_closed_is_valid(): void
    {
        $this->sm->transition(WalletStatus::Restricted, WalletStatus::Closed);
        $this->assertTrue(true);
    }

    public function test_closed_to_any_throws(): void
    {
        foreach ([
            WalletStatus::Active,
            WalletStatus::Frozen,
            WalletStatus::Suspended,
            WalletStatus::Restricted,
            WalletStatus::Pending,
        ] as $target) {
            try {
                $this->sm->transition(WalletStatus::Closed, $target);
                $this->fail('Expected InvalidWalletTransitionException for Closed → ' . $target->value);
            } catch (InvalidWalletTransitionException $e) {
                $this->assertSame(WalletStatus::Closed, $e->fromStatus);
                $this->assertSame($target, $e->toStatus);
            }
        }
    }
}
