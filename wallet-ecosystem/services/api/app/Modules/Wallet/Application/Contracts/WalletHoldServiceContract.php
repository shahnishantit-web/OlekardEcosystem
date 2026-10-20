<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application\Contracts;

use App\Modules\Wallet\Infrastructure\Models\WalletHoldModel;

/**
 * Contract for the internal E06 hold service.
 * NOT exposed cross-module (FR-E06-048, research Decision 6).
 * E08/E09 will receive a separate cross-module interface when those epics are designed.
 *
 * Concurrency contract (CC-E06-001 / CC-E06-002):
 *   place() MUST use SELECT FOR UPDATE on the currency_pockets row before any balance check.
 *   The lock-then-check sequence prevents concurrent overdrafts.
 */
interface WalletHoldServiceContract
{
    /**
     * Place a hold on a wallet pocket.
     *
     * Lock sequence:
     *   BEGIN
     *   SELECT * FROM wallet.currency_pockets WHERE id = :pocket_id FOR UPDATE
     *   -- compute available_balance within lock scope
     *   -- if amount <= available_balance: INSERT wallet_holds; COMMIT
     *   -- else: ROLLBACK; throw InsufficientBalanceException
     *
     * @throws \App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException
     * @throws \App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException
     * @throws \App\Modules\Wallet\Domain\Exceptions\WalletFrozenException
     */
    public function place(
        string $walletId,
        string $currencyCode,
        int $amount,
        string $actorId,
        string $correlationId,
        ?string $transactionReference = null,
        ?string $description = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): WalletHoldModel;

    /**
     * Release a previously placed hold.
     *
     * @throws \App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException
     */
    public function release(string $holdId, string $correlationId): void;
}
