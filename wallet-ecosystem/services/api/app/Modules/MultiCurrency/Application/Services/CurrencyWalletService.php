<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\Services;

use App\Modules\MultiCurrency\Application\DTOs\CreateCurrencyWalletDTO;
use App\Modules\MultiCurrency\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\CurrencyWalletRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Events\CurrencyWalletCreatedEvent;
use App\Modules\MultiCurrency\Domain\Exceptions\CurrencyNotFoundException;
use App\Modules\MultiCurrency\Domain\Exceptions\CurrencyWalletAlreadyExistsException;
use App\Modules\MultiCurrency\Infrastructure\Models\CurrencyWalletModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class CurrencyWalletService
{
    public function __construct(
        private readonly CurrencyRepositoryInterface       $currencies,
        private readonly CurrencyWalletRepositoryInterface $currencyWallets,
        private readonly WalletModuleContract              $wallet,
        private readonly LedgerModuleContract              $ledger,
        private readonly EventPublisherInterface           $events,
        private readonly AuditServiceInterface             $audit,
    ) {}

    public function create(CreateCurrencyWalletDTO $dto): CurrencyWalletModel
    {
        // Idempotency: same-key replay returns the existing wallet without error
        $existingByKey = $this->currencyWallets->findByIdempotencyKey($dto->idempotencyKey);
        if ($existingByKey !== null) {
            return $existingByKey;
        }

        // Different-key duplicate: a wallet already exists in this currency for this customer
        $existing = $this->currencyWallets->findByCustomerAndCurrency($dto->customerId, $dto->currencyCode);
        if ($existing !== null) {
            throw new CurrencyWalletAlreadyExistsException($dto->currencyCode);
        }

        // Validate currency is active
        $currency = $this->currencies->findByCode($dto->currencyCode);
        if ($currency === null || ! $currency->is_active) {
            throw new CurrencyNotFoundException($dto->currencyCode);
        }

        // Find customer's primary wallet in E06
        $primaryWalletId = $this->wallet->findPrimaryWalletId($dto->customerId);
        if ($primaryWalletId === null) {
            throw new \DomainException("No active wallet found for customer [{$dto->customerId}]. Customer must have a provisioned wallet before creating currency wallets.");
        }

        return DB::connection('platform')->transaction(function () use ($dto, $primaryWalletId): CurrencyWalletModel {
            // Add the currency pocket to E06 wallet (idempotent, returns existing ref if exists)
            $ledgerRef = $this->wallet->addCurrencyPocket(
                walletId:      $primaryWalletId,
                currencyCode:  $dto->currencyCode,
                actorId:       $dto->customerId,
                correlationId: $dto->correlationId,
            );

            $model                            = new CurrencyWalletModel();
            $model->id                        = (string) Uuid::uuid7();
            $model->customer_id               = $dto->customerId;
            $model->currency_code             = strtoupper($dto->currencyCode);
            $model->wallet_id                 = $primaryWalletId;
            $model->pocket_ledger_account_reference = $ledgerRef;
            $model->idempotency_key           = $dto->idempotencyKey;
            $model->is_active                 = true;
            $this->currencyWallets->save($model);

            $this->events->publish(new CurrencyWalletCreatedEvent(
                customerId:       $dto->customerId,
                currencyWalletId: $model->id,
                currencyCode:     $model->currency_code,
                walletId:         $primaryWalletId,
                occurredAt:       now()->toIso8601String(),
                correlationId:    $dto->correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'multi_currency.wallet.created',
                actorId:       $dto->customerId,
                actorType:     'CUSTOMER',
                resourceType:  'currency_wallet',
                resourceId:    $model->id,
                correlationId: $dto->correlationId,
                outcome:       'success',
                afterState:    ['currency_code' => $model->currency_code, 'wallet_id' => $primaryWalletId],
            ));

            return $model;
        });
    }

    public function list(string $customerId): Collection
    {
        return $this->currencyWallets->listByCustomer($customerId);
    }

    public function get(string $customerId, string $walletId): CurrencyWalletModel
    {
        $model = $this->currencyWallets->findById($walletId);
        if ($model === null || $model->customer_id !== $customerId) {
            throw new \DomainException("Currency wallet [{$walletId}] not found.");
        }
        return $model;
    }

    public function getBalance(string $pocketLedgerAccountRef): int
    {
        return $this->ledger->getBalance($pocketLedgerAccountRef);
    }
}
