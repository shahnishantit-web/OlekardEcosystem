<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Exceptions\InvalidWalletTransitionException;
use App\Modules\Wallet\Domain\Services\WalletStateMachine;
use PHPUnit\Framework\TestCase;

final class WalletStateMachineTest extends TestCase
{
    private WalletStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new WalletStateMachine();
    }

    /** @test */
    public function pending_to_active_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Pending, WalletStatus::Active);
        $this->assertTrue(true);
    }

    /** @test */
    public function pending_to_closed_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Pending, WalletStatus::Closed);
        $this->assertTrue(true);
    }

    /** @test */
    public function active_to_frozen_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Active, WalletStatus::Frozen);
        $this->assertTrue(true);
    }

    /** @test */
    public function active_to_suspended_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Active, WalletStatus::Suspended);
        $this->assertTrue(true);
    }

    /** @test */
    public function active_to_restricted_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Active, WalletStatus::Restricted);
        $this->assertTrue(true);
    }

    /** @test */
    public function active_to_closed_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Active, WalletStatus::Closed);
        $this->assertTrue(true);
    }

    /** @test */
    public function frozen_to_active_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Frozen, WalletStatus::Active);
        $this->assertTrue(true);
    }

    /** @test */
    public function frozen_to_closed_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Frozen, WalletStatus::Closed);
        $this->assertTrue(true);
    }

    /** @test */
    public function suspended_to_active_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Suspended, WalletStatus::Active);
        $this->assertTrue(true);
    }

    /** @test */
    public function suspended_to_closed_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Suspended, WalletStatus::Closed);
        $this->assertTrue(true);
    }

    /** @test */
    public function restricted_to_active_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Restricted, WalletStatus::Active);
        $this->assertTrue(true);
    }

    /** @test */
    public function restricted_to_closed_is_valid(): void
    {
        $this->machine->transition(WalletStatus::Restricted, WalletStatus::Closed);
        $this->assertTrue(true);
    }

    /** @test */
    public function closed_to_active_is_invalid(): void
    {
        $this->expectException(InvalidWalletTransitionException::class);
        $this->machine->transition(WalletStatus::Closed, WalletStatus::Active);
    }

    /** @test */
    public function closed_to_frozen_is_invalid(): void
    {
        $this->expectException(InvalidWalletTransitionException::class);
        $this->machine->transition(WalletStatus::Closed, WalletStatus::Frozen);
    }

    /** @test */
    public function closed_to_closed_is_invalid(): void
    {
        $this->expectException(InvalidWalletTransitionException::class);
        $this->machine->transition(WalletStatus::Closed, WalletStatus::Closed);
    }

    /** @test */
    public function pending_to_frozen_is_invalid(): void
    {
        $this->expectException(InvalidWalletTransitionException::class);
        $this->machine->transition(WalletStatus::Pending, WalletStatus::Frozen);
    }

    /** @test */
    public function pending_to_suspended_is_invalid(): void
    {
        $this->expectException(InvalidWalletTransitionException::class);
        $this->machine->transition(WalletStatus::Pending, WalletStatus::Suspended);
    }

    /** @test */
    public function frozen_to_suspended_is_invalid(): void
    {
        $this->expectException(InvalidWalletTransitionException::class);
        $this->machine->transition(WalletStatus::Frozen, WalletStatus::Suspended);
    }

    /** @test */
    public function active_to_active_is_invalid(): void
    {
        $this->expectException(InvalidWalletTransitionException::class);
        $this->machine->transition(WalletStatus::Active, WalletStatus::Active);
    }

    /** @test */
    public function exception_carries_from_and_to_status(): void
    {
        try {
            $this->machine->transition(WalletStatus::Closed, WalletStatus::Active);
            $this->fail('Expected exception not thrown.');
        } catch (InvalidWalletTransitionException $e) {
            $this->assertSame('closed', $e->fromStatus);
            $this->assertSame('active', $e->toStatus);
        }
    }
}
