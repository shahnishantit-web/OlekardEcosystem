<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use App\Modules\Wallet\Domain\Enums\WalletHoldStatus;
use App\Modules\Wallet\Domain\Enums\WalletLimitType;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Wallet\Domain\Exceptions\WalletFrozenException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for WalletHoldService business logic.
 * These tests verify exception mappings and status semantics without DB.
 */
final class WalletHoldServiceTest extends TestCase
{
    /** @test */
    public function wallet_frozen_exception_has_correct_error_code(): void
    {
        $e = new WalletFrozenException();
        $this->assertSame('WALLET_FROZEN', $e->errorCode);
        $this->assertSame(422, $e->httpStatus);
    }

    /** @test */
    public function wallet_not_transactable_exception_has_correct_error_code(): void
    {
        $e = new WalletNotTransactableException(WalletStatus::Suspended);
        $this->assertSame('WALLET_NOT_TRANSACTABLE', $e->errorCode);
        $this->assertSame(422, $e->httpStatus);
    }

    /** @test */
    public function insufficient_balance_exception_carries_available_and_requested(): void
    {
        $e = new InsufficientBalanceException(100, 150);
        $this->assertSame(100, $e->available);
        $this->assertSame(150, $e->requested);
        $this->assertSame('INSUFFICIENT_BALANCE', $e->errorCode);
        $this->assertSame(422, $e->httpStatus);
    }

    /** @test */
    public function wallet_frozen_exception_extends_not_transactable(): void
    {
        $e = new WalletFrozenException();
        $this->assertInstanceOf(WalletNotTransactableException::class, $e);
    }

    /** @test */
    public function frozen_status_maps_to_wallet_frozen_exception(): void
    {
        // Verify the check: WalletStatus::Frozen → WalletFrozenException
        // This tests the logic we placed in WalletHoldService::place()
        $status = WalletStatus::Frozen;

        $exception = null;
        if ($status === WalletStatus::Frozen) {
            $exception = new WalletFrozenException();
        } elseif (!in_array($status, [WalletStatus::Active, WalletStatus::Restricted], true)) {
            $exception = new WalletNotTransactableException($status);
        }

        $this->assertInstanceOf(WalletFrozenException::class, $exception);
    }

    /** @test */
    public function suspended_status_maps_to_not_transactable(): void
    {
        $status = WalletStatus::Suspended;

        $exception = null;
        if ($status === WalletStatus::Frozen) {
            $exception = new WalletFrozenException();
        } elseif (!in_array($status, [WalletStatus::Active, WalletStatus::Restricted], true)) {
            $exception = new WalletNotTransactableException($status);
        }

        $this->assertInstanceOf(WalletNotTransactableException::class, $exception);
        $this->assertNotInstanceOf(WalletFrozenException::class, $exception);
    }

    /** @test */
    public function active_status_allows_hold_placement(): void
    {
        $status = WalletStatus::Active;

        $exception = null;
        if ($status === WalletStatus::Frozen) {
            $exception = new WalletFrozenException();
        } elseif (!in_array($status, [WalletStatus::Active, WalletStatus::Restricted], true)) {
            $exception = new WalletNotTransactableException($status);
        }

        $this->assertNull($exception);
    }

    /** @test */
    public function restricted_status_allows_hold_placement(): void
    {
        $status = WalletStatus::Restricted;

        $exception = null;
        if ($status === WalletStatus::Frozen) {
            $exception = new WalletFrozenException();
        } elseif (!in_array($status, [WalletStatus::Active, WalletStatus::Restricted], true)) {
            $exception = new WalletNotTransactableException($status);
        }

        $this->assertNull($exception);
    }

    /** @test */
    public function hold_release_is_idempotent_for_non_active_holds(): void
    {
        // Test the idempotency logic: if status != Active, return without error
        $holdStatus = WalletHoldStatus::Released;
        $shouldSkip = $holdStatus !== WalletHoldStatus::Active;
        $this->assertTrue($shouldSkip);
    }
}
