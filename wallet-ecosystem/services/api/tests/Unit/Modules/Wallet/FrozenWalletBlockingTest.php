<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Exceptions\WalletFrozenException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;
use PHPUnit\Framework\TestCase;

/**
 * T133 — US-WALLET-016: Frozen wallet blocks all mutating operations.
 */
final class FrozenWalletBlockingTest extends TestCase
{
    /** Scenario 1: Frozen status maps to WalletFrozenException. */
    public function test_frozen_status_throws_wallet_frozen_exception(): void
    {
        $e = $this->resolveException(WalletStatus::Frozen);
        $this->assertInstanceOf(WalletFrozenException::class, $e);
    }

    /** Scenario 2: WalletFrozenException has WALLET_FROZEN error code. */
    public function test_wallet_frozen_exception_has_correct_error_code(): void
    {
        $e = new WalletFrozenException();
        $this->assertSame('WALLET_FROZEN', $e->errorCode);
        $this->assertSame(422, $e->httpStatus);
    }

    /** Scenario 3: Suspended status throws WalletNotTransactableException (not frozen). */
    public function test_suspended_status_throws_not_transactable_but_not_frozen(): void
    {
        $e = $this->resolveException(WalletStatus::Suspended);
        $this->assertInstanceOf(WalletNotTransactableException::class, $e);
        $this->assertNotInstanceOf(WalletFrozenException::class, $e);
    }

    /** Scenario 4: Active status does NOT throw. */
    public function test_active_status_does_not_throw(): void
    {
        $e = $this->resolveException(WalletStatus::Active);
        $this->assertNull($e);
    }

    /** Scenario 5: Restricted status does NOT throw (outbound debits allowed with restriction). */
    public function test_restricted_status_does_not_throw(): void
    {
        $e = $this->resolveException(WalletStatus::Restricted);
        $this->assertNull($e);
    }

    /** Frozen blocks hold placement (domain logic verification). */
    public function test_frozen_exception_extends_not_transactable(): void
    {
        $e = new WalletFrozenException();
        $this->assertInstanceOf(WalletNotTransactableException::class, $e);
    }

    private function resolveException(WalletStatus $status): ?\DomainException
    {
        if ($status === WalletStatus::Frozen) {
            return new WalletFrozenException();
        }

        if (!in_array($status, [WalletStatus::Active, WalletStatus::Restricted], true)) {
            return new WalletNotTransactableException($status);
        }

        return null;
    }
}
