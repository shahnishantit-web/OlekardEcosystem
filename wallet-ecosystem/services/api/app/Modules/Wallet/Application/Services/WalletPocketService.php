<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application\Services;

use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Enums\PocketStatus;
use App\Modules\Wallet\Domain\Events\WalletPocketAddedEvent;
use App\Modules\Wallet\Domain\Exceptions\PocketAlreadyExistsException;
use App\Modules\Wallet\Domain\Exceptions\UnsupportedCurrencyException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException;
use App\Modules\Wallet\Infrastructure\Models\CurrencyPocketModel;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentCurrencyPocketRepository;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class WalletPocketService
{
    public function __construct(
        private readonly EloquentWalletRepository         $walletRepository,
        private readonly EloquentCurrencyPocketRepository $pocketRepository,
        private readonly EventPublisherInterface          $eventPublisher,
    ) {}

    /**
     * Add a currency pocket to an existing wallet.
     *
     * @throws WalletNotFoundException
     * @throws UnsupportedCurrencyException
     * @throws PocketAlreadyExistsException
     */
    public function addPocket(
        string $walletId,
        string $currencyCode,
        string $actorId,
        string $correlationId,
    ): CurrencyPocketModel {
        $wallet = $this->walletRepository->findById($walletId);
        if ($wallet === null) {
            throw new WalletNotFoundException($walletId);
        }

        // Validate currency is enabled
        $currency = \App\Modules\Wallet\Infrastructure\Models\CurrencyModel::on('wallet')
            ->find(strtoupper($currencyCode));

        if ($currency === null || !$currency->is_enabled) {
            throw new UnsupportedCurrencyException($currencyCode);
        }

        // Check for existing pocket
        $existing = $this->pocketRepository->findByWalletAndCurrency($walletId, strtoupper($currencyCode));
        if ($existing !== null) {
            throw new PocketAlreadyExistsException($currencyCode);
        }

        return DB::connection('wallet')->transaction(function () use ($walletId, $currencyCode, $actorId, $correlationId): CurrencyPocketModel {
            $pocketId  = (string) Uuid::uuid7();
            $ledgerRef = (string) Uuid::uuid7();

            $pocket                           = new CurrencyPocketModel();
            $pocket->id                       = $pocketId;
            $pocket->wallet_id                = $walletId;
            $pocket->currency_code            = strtoupper($currencyCode);
            $pocket->ledger_account_reference = $ledgerRef;
            $pocket->status                   = PocketStatus::Active;
            $pocket->created_by               = $actorId;
            $pocket->save();

            $this->eventPublisher->publish(new WalletPocketAddedEvent(
                walletId:              $walletId,
                pocketId:              $pocketId,
                currencyCode:          strtoupper($currencyCode),
                ledgerAccountReference: $ledgerRef,
                actorId:               $actorId,
                correlationId:         $correlationId,
            ));

            return $pocket;
        });
    }
}
