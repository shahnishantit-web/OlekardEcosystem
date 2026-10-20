<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application\Services;

use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use App\Modules\Wallet\Domain\Enums\WalletHoldStatus;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Wallet\Domain\Exceptions\WalletFrozenException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;
use App\Modules\Wallet\Infrastructure\Models\WalletHoldModel;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentCurrencyPocketRepository;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class WalletHoldService implements WalletHoldServiceContract
{
    public function __construct(
        private readonly EloquentWalletRepository         $walletRepository,
        private readonly EloquentCurrencyPocketRepository $pocketRepository,
        private readonly WalletBalanceService             $balanceService,
        private readonly WalletLimitService               $limitService,
    ) {}

    /**
     * Place a hold using SELECT FOR UPDATE to prevent concurrent overdrafts.
     * Lock sequence: BEGIN → SELECT FOR UPDATE on pocket → compute balance → INSERT hold or ROLLBACK.
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
    ): WalletHoldModel {
        return DB::connection('wallet')->transaction(function () use (
            $walletId, $currencyCode, $amount, $actorId, $correlationId,
            $transactionReference, $description, $expiresAt
        ): WalletHoldModel {
            $wallet = $this->walletRepository->findById($walletId);
            if ($wallet === null) {
                throw new WalletNotFoundException($walletId);
            }

            // Status check before balance check
            if ($wallet->status === WalletStatus::Frozen) {
                throw new WalletFrozenException();
            }

            if (!in_array($wallet->status, [WalletStatus::Active, WalletStatus::Restricted], true)) {
                throw new WalletNotTransactableException($wallet->status);
            }

            // SELECT FOR UPDATE on pocket to serialize concurrent debits
            $pocket = \App\Modules\Wallet\Infrastructure\Models\CurrencyPocketModel::on('wallet')
                ->lockForUpdate()
                ->where('wallet_id', $walletId)
                ->where('currency_code', strtoupper($currencyCode))
                ->first();

            if ($pocket === null) {
                throw new WalletNotFoundException("{$walletId}/{$currencyCode}");
            }

            // Compute balance within lock scope
            $balanceData = $this->balanceService->computeLockedBalance($pocket);

            if ($amount > $balanceData['available_balance']) {
                throw new InsufficientBalanceException($balanceData['available_balance'], $amount);
            }

            // Enforce limits (TBD limits are skipped per M1 fix)
            $this->limitService->enforceLimits(
                $walletId,
                strtoupper($currencyCode),
                \App\Modules\Wallet\Domain\Enums\WalletLimitType::SingleTransactionAmount,
                $amount,
            );

            $hold                       = new WalletHoldModel();
            $hold->id                   = (string) Uuid::uuid7();
            $hold->pocket_id            = $pocket->id;
            $hold->amount               = $amount;
            $hold->currency_code        = strtoupper($currencyCode);
            $hold->status               = WalletHoldStatus::Active;
            $hold->transaction_reference = $transactionReference;
            $hold->description          = $description;
            $hold->expires_at           = $expiresAt;
            $hold->created_by           = $actorId;
            $hold->save();

            return $hold;
        });
    }

    public function release(string $holdId, string $correlationId): void
    {
        DB::connection('wallet')->transaction(function () use ($holdId): void {
            $hold = WalletHoldModel::on('wallet')->lockForUpdate()->find($holdId);

            if ($hold === null) {
                throw new WalletNotFoundException("Hold not found: {$holdId}");
            }

            if ($hold->status !== WalletHoldStatus::Active) {
                // Already released or expired — idempotent, no error
                return;
            }

            $hold->status      = WalletHoldStatus::Released;
            $hold->released_at = now();
            $hold->save();
        });
    }
}
