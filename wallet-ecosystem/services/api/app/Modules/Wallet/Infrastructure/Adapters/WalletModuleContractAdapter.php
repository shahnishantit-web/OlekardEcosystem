<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Adapters;

use App\Modules\Platform\Domain\Contracts\CurrencyContract;
use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use App\Modules\Wallet\Application\Services\WalletBalanceService;
use App\Modules\Wallet\Application\Services\WalletPocketService;
use App\Modules\Wallet\Application\Services\WalletService;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Wallet\Domain\Exceptions\PocketAlreadyExistsException;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentCurrencyPocketRepository;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;

final class WalletModuleContractAdapter implements WalletModuleContract
{
    public function __construct(
        private readonly WalletService                    $walletService,
        private readonly WalletBalanceService             $balanceService,
        private readonly EloquentWalletRepository         $walletRepository,
        private readonly EloquentCurrencyPocketRepository $pocketRepository,
        private readonly WalletHoldServiceContract        $holdService,
        private readonly WalletPocketService              $pocketService,
    ) {}

    public function isActive(string $walletId): bool
    {
        return $this->walletService->isActive($walletId);
    }

    public function ownerActorId(string $walletId): ?string
    {
        return $this->walletService->ownerActorId($walletId);
    }

    public function currency(string $walletId): ?CurrencyContract
    {
        return $this->walletService->currency($walletId);
    }

    public function pocketExists(string $walletId, string $currencyCode): bool
    {
        return $this->pocketRepository->findByWalletAndCurrency($walletId, $currencyCode) !== null;
    }

    public function availableBalance(string $walletId, string $currencyCode): ?int
    {
        try {
            $data = $this->balanceService->availableBalance($walletId, $currencyCode);
            return $data;
        } catch (\App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException) {
            return null;
        }
    }

    public function createHold(
        string $walletId,
        string $currencyCode,
        int $amount,
        string $actorId,
        string $correlationId,
        string $transactionReference,
        ?\DateTimeImmutable $expiresAt = null,
    ): string {
        $hold = $this->holdService->place(
            walletId: $walletId,
            currencyCode: $currencyCode,
            amount: $amount,
            actorId: $actorId,
            correlationId: $correlationId,
            transactionReference: $transactionReference,
            expiresAt: $expiresAt,
        );

        return $hold->id;
    }

    public function releaseHold(string $holdId, string $correlationId): void
    {
        $this->holdService->release($holdId, $correlationId);
    }

    public function getPocketLedgerAccountRef(string $walletId, string $currencyCode): ?string
    {
        $pocket = $this->pocketRepository->findByWalletAndCurrency($walletId, $currencyCode);
        return $pocket?->ledger_account_reference;
    }

    public function findPrimaryWalletId(string $ownerId): ?string
    {
        return $this->walletRepository->findActiveByOwnerId($ownerId)?->id;
    }

    public function addCurrencyPocket(
        string $walletId,
        string $currencyCode,
        string $actorId,
        string $correlationId,
    ): string {
        try {
            $pocket = $this->pocketService->addPocket($walletId, $currencyCode, $actorId, $correlationId);
            return $pocket->ledger_account_reference;
        } catch (PocketAlreadyExistsException) {
            // Idempotent: return the existing ledger account reference
            $existing = $this->pocketRepository->findByWalletAndCurrency($walletId, $currencyCode);
            return $existing->ledger_account_reference;
        }
    }
}
