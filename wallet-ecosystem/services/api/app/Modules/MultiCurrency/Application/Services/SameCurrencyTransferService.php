<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\Services;

use App\Modules\MultiCurrency\Application\DTOs\SameCurrencyTransferDTO;
use App\Modules\MultiCurrency\Domain\Contracts\CurrencyWalletRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\SameCurrencyTransferRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Events\SameCurrencyTransferCompletedEvent;
use App\Modules\MultiCurrency\Domain\Exceptions\CurrencyMismatchException;
use App\Modules\MultiCurrency\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\MultiCurrency\Infrastructure\Models\SameCurrencyTransferModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class SameCurrencyTransferService
{
    public function __construct(
        private readonly CurrencyWalletRepositoryInterface       $currencyWallets,
        private readonly SameCurrencyTransferRepositoryInterface $transferRepo,
        private readonly LedgerModuleContract                    $ledger,
        private readonly EventPublisherInterface                 $events,
        private readonly AuditServiceInterface                   $audit,
    ) {}

    public function transfer(SameCurrencyTransferDTO $dto): SameCurrencyTransferModel
    {
        // Idempotency: return existing transfer record if already processed
        $existing = $this->transferRepo->findByIdempotencyKey($dto->idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        // Load and validate both wallets belong to the customer
        $fromWallet = $this->currencyWallets->findById($dto->fromWalletId);
        $toWallet   = $this->currencyWallets->findById($dto->toWalletId);

        if ($fromWallet === null || $fromWallet->customer_id !== $dto->customerId) {
            throw new \DomainException("Source wallet [{$dto->fromWalletId}] not found or not owned by customer.");
        }
        if ($toWallet === null || $toWallet->customer_id !== $dto->customerId) {
            throw new \DomainException("Destination wallet [{$dto->toWalletId}] not found or not owned by customer.");
        }

        // Enforce same-currency rule
        if ($fromWallet->currency_code !== $toWallet->currency_code) {
            throw new CurrencyMismatchException();
        }

        // Convert decimal amount to minor units for ledger
        $amountMinor = (int) bcmul($dto->amount, '100', 0);

        return DB::connection('platform')->transaction(function () use ($dto, $fromWallet, $toWallet, $amountMinor): SameCurrencyTransferModel {
            // Lock source wallet to prevent double-spend
            $lockedSource = $this->currencyWallets->lockForUpdate($dto->fromWalletId);
            if ($lockedSource === null) {
                throw new \DomainException("Source currency wallet not found for lock.");
            }

            // Balance check using ledger
            $availableMinor = $this->ledger->getBalance($lockedSource->pocket_ledger_account_reference);
            if ($availableMinor < $amountMinor) {
                throw new InsufficientBalanceException();
            }

            // Post two-legged journal entry via E07 LedgerService
            $this->ledger->postEntry([
                'idempotency_key'       => 'same_currency_transfer:' . $dto->idempotencyKey,
                'entry_type'            => 'debit',
                'currency_code'         => $fromWallet->currency_code,
                'transaction_reference' => $dto->idempotencyKey,
                'correlation_id'        => $dto->correlationId,
                'description'           => "Same-currency wallet transfer",
                'lines'                 => [
                    [
                        'ledger_account_reference' => $fromWallet->pocket_ledger_account_reference,
                        'direction'                => 'debit',
                        'amount'                   => $amountMinor,
                    ],
                    [
                        'ledger_account_reference' => $toWallet->pocket_ledger_account_reference,
                        'direction'                => 'credit',
                        'amount'                   => $amountMinor,
                    ],
                ],
            ]);

            // Persist idempotency record within same transaction
            $record                  = new SameCurrencyTransferModel();
            $record->id              = (string) Uuid::uuid7();
            $record->customer_id     = $dto->customerId;
            $record->from_wallet_id  = $dto->fromWalletId;
            $record->to_wallet_id    = $dto->toWalletId;
            $record->currency_code   = $fromWallet->currency_code;
            $record->amount          = $dto->amount;
            $record->idempotency_key = $dto->idempotencyKey;
            $this->transferRepo->save($record);

            $this->events->publish(new SameCurrencyTransferCompletedEvent(
                customerId:    $dto->customerId,
                transferId:    $record->id,
                fromWalletId:  $dto->fromWalletId,
                toWalletId:    $dto->toWalletId,
                currencyCode:  $fromWallet->currency_code,
                amount:        $dto->amount,
                occurredAt:    now()->toIso8601String(),
                correlationId: $dto->correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'multi_currency.transfer.completed',
                actorId:       $dto->customerId,
                actorType:     'CUSTOMER',
                resourceType:  'same_currency_transfer',
                resourceId:    $record->id,
                correlationId: $dto->correlationId,
                outcome:       'success',
                afterState:    ['amount' => $dto->amount, 'currency' => $fromWallet->currency_code],
            ));

            return $record;
        });
    }
}
