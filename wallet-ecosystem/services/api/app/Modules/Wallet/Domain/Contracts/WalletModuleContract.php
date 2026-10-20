<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Contracts;

use App\Modules\Platform\Domain\Contracts\CurrencyContract;

interface WalletModuleContract
{
    /** Returns true if the wallet exists and is in an active, operable state. */
    public function isActive(string $walletId): bool;

    /** Returns the UUID of the actor who owns the wallet, or null if the wallet does not exist. */
    public function ownerActorId(string $walletId): ?string;

    /**
     * Returns the primary/home currency of the wallet (BWP by default).
     * Returns null if the wallet does not exist.
     */
    public function currency(string $walletId): ?CurrencyContract;

    /** Returns true if a pocket for the given currency code exists on the wallet. */
    public function pocketExists(string $walletId, string $currencyCode): bool;

    /**
     * Returns the ledger account reference for the given wallet pocket.
     * Returns null if the wallet or pocket does not exist.
     *
     * Added for E08: FundingCreditService needs to resolve the pocket account reference
     * before calling LedgerModuleContract::postEntry().
     */
    public function getPocketLedgerAccountRef(string $walletId, string $currencyCode): ?string;

    /**
     * Returns the available balance (integer minor units) for the given pocket.
     * Returns null if the wallet or pocket does not exist.
     */
    public function availableBalance(string $walletId, string $currencyCode): ?int;

    /**
     * Create a hold on a wallet pocket, reducing available_balance atomically.
     *
     * Delegates to WalletHoldService::place() which handles SELECT FOR UPDATE
     * + balance check + hold creation internally.
     *
     * @throws \App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException
     * @throws \App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException
     * @return string Hold ID (UUID v7)
     */
    public function createHold(
        string $walletId,
        string $currencyCode,
        int $amount,
        string $actorId,
        string $correlationId,
        string $transactionReference,
        ?\DateTimeImmutable $expiresAt = null,
    ): string;

    /**
     * Release a previously placed hold, restoring available_balance.
     * Idempotent: releasing an already-released hold is a no-op.
     */
    public function releaseHold(string $holdId, string $correlationId): void;

    /**
     * Returns the primary wallet ID for the given owner actor, or null if no wallet found.
     * Added for E29: MultiCurrency module needs to locate the underlying wallet before adding pockets.
     */
    public function findPrimaryWalletId(string $ownerId): ?string;

    /**
     * Adds a currency pocket to the given wallet and returns the pocket's ledger account reference.
     * Idempotent: if the pocket already exists, returns the existing reference.
     * Added for E29: each new currency wallet is backed by a pocket on the customer's primary wallet.
     *
     * @throws \App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException
     * @throws \App\Modules\Wallet\Domain\Exceptions\UnsupportedCurrencyException
     */
    public function addCurrencyPocket(
        string $walletId,
        string $currencyCode,
        string $actorId,
        string $correlationId,
    ): string;
}
