<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Contracts;

use App\Modules\Platform\Domain\Contracts\CurrencyContract;

/**
 * WalletModuleContract — the only cross-module interface for wallet data (FR-E06-046).
 *
 * Consuming epics (E08, E09, E11, etc.) MUST depend solely on this interface.
 * Direct references to Wallet Eloquent models from outside App\Modules\Wallet\
 * are prohibited (FR-E06-049).
 *
 * All methods throw ModuleNotImplementedException in the NullWalletModule
 * until E06 implementation is active.
 */
interface WalletModuleContract
{
    /**
     * Returns true if the wallet exists and is in `active` status.
     * Returns false for pending, frozen, suspended, restricted, closed, or non-existent.
     */
    public function isActive(string $walletId): bool;

    /**
     * Returns the UUID of the actor who owns the wallet.
     * Returns null if the wallet does not exist.
     */
    public function ownerActorId(string $walletId): ?string;

    /**
     * Returns the wallet's primary/home currency (BWP by default).
     * For currency-specific pocket queries use pocketExists().
     */
    public function currency(string $walletId): CurrencyContract;

    /**
     * Returns true if the wallet has a pocket for the given ISO 4217 currency code.
     * Returns false if the wallet does not exist or has no such pocket.
     */
    public function pocketExists(string $walletId, string $currencyCode): bool;

    /**
     * Returns the available balance (in minor currency units) for the given pocket.
     * available_balance = max(0, ledger_balance − held_amount)
     * Returns 0 if the wallet or pocket does not exist.
     * Throws when called before E07 provides LedgerServiceContract::getBalance().
     */
    public function availableBalance(string $walletId, string $currencyCode): int;
}
