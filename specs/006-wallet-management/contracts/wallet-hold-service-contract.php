<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application\Contracts;

/**
 * WalletHoldService interface (internal E06 contract).
 *
 * E08 (Wallet Funding) and E09 (Wallet Transfer) MUST NOT write wallet_holds
 * records directly. They call this internal E06 service via dependency injection
 * within the monolith boundary (spec Section 11.3 — C4 resolution).
 *
 * This interface is NOT part of WalletModuleContract. It is an internal
 * Application-layer contract. Future epics that need cross-module hold placement
 * will require an explicit ADR.
 *
 * NOTE: This is a contract specification file only. The actual implementation
 * goes in:
 *   wallet-ecosystem/services/api/app/Modules/Wallet/Application/Services/WalletHoldService.php
 */
interface WalletHoldServiceContract
{
    /**
     * Places a hold on a currency pocket.
     *
     * Enforces the lock-then-check sequence:
     *   1. BEGIN transaction
     *   2. SELECT FOR UPDATE on currency_pockets row
     *   3. Compute available_balance (ledger_balance − active holds)
     *   4. Verify debit_amount <= available_balance
     *   5. INSERT wallet_holds record
     *   6. COMMIT
     *
     * @param string                $pocketId       UUID of the currency_pocket
     * @param int                   $amount         Hold amount in minor currency units (positive integer)
     * @param string                $correlationId  Request correlation ID
     * @param \DateTimeImmutable|null $expiresAt    Optional hold expiry (UTC)
     * @param string|null           $transactionRef Opaque reference to initiating transaction
     * @param string|null           $description    Human-readable reason for the hold
     *
     * @return object Hold record (id, pocket_id, amount, currency_code, status, created_at)
     *
     * @throws \App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException
     * @throws \App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException
     */
    public function place(
        string $pocketId,
        int $amount,
        string $correlationId,
        ?\DateTimeImmutable $expiresAt = null,
        ?string $transactionRef = null,
        ?string $description = null,
    ): object;

    /**
     * Releases an active hold after transaction settlement or cancellation.
     *
     * @param string $holdId        UUID of the wallet_hold to release
     * @param string $correlationId Request correlation ID
     */
    public function release(string $holdId, string $correlationId): void;
}
