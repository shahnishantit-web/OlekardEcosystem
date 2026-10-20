<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Services;

use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Domain\Enums\LedgerAccountStatus;
use App\Modules\Ledger\Domain\Events\LedgerAccountRegisteredEvent;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel;
use App\Modules\Ledger\Infrastructure\Repositories\EloquentLedgerAccountRepository;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class LedgerAccountService
{
    public function __construct(
        private readonly EloquentLedgerAccountRepository $accountRepo,
        private readonly EventPublisherInterface $outboxPublisher,
        private readonly AuditServiceInterface $auditService,
    ) {}

    /**
     * Explicitly register a ledger account with owner context.
     * Idempotent: existing account with same parameters is a no-op.
     * Called from the explicit POST /v1/ledger/accounts endpoint.
     *
     * @throws \RuntimeException if account exists with conflicting type or currency
     */
    public function registerAccount(
        string $ref,
        string $accountType,
        string $currencyCode,
        string $ownerRef,
        string $ownerType,
        string $actorId,
        string $correlationId,
    ): LedgerAccountModel {
        return DB::connection('platform')->transaction(function () use (
            $ref, $accountType, $currencyCode, $ownerRef, $ownerType, $actorId, $correlationId
        ): LedgerAccountModel {
            $existing = $this->accountRepo->findByReference($ref);

            if ($existing !== null) {
                $existingType     = $existing->account_type instanceof \BackedEnum
                    ? $existing->account_type->value : $existing->account_type;
                $existingCurrency = $existing->currency_code;

                if ($existingType !== $accountType || $existingCurrency !== $currencyCode) {
                    throw new \RuntimeException(
                        "Ledger account '{$ref}' already registered with conflicting account_type or currency_code."
                    );
                }

                return $existing;
            }

            $accountTypeEnum = AccountType::from($accountType);

            $model = $this->accountRepo->create([
                'id'                       => (string) Uuid::uuid7(),
                'ledger_account_reference' => $ref,
                'account_type'             => $accountTypeEnum->value,
                'normal_balance'           => $accountTypeEnum->normalBalance()->value,
                'currency_code'            => $currencyCode,
                'owner_reference'          => $ownerRef,
                'owner_type'               => $ownerType,
                'status'                   => LedgerAccountStatus::Active->value,
                'created_by'               => $actorId,
            ]);

            $this->outboxPublisher->publish(new LedgerAccountRegisteredEvent(
                ledgerAccountId:        $model->id,
                ledgerAccountReference: $ref,
                accountType:            $accountType,
                currencyCode:           $currencyCode,
                ownerType:              $ownerType,
                actorId:                $actorId,
                correlationId:          $correlationId,
            ));

            $this->auditService->record(new AuditEventData(
                action:        'ledger.account.registered',
                actorType:     'ADMIN',
                resourceType:  'ledger_account',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $model->id,
                afterState:    ['ledger_account_reference' => $ref, 'account_type' => $accountType, 'currency_code' => $currencyCode],
            ));

            return $model;
        });
    }

    /**
     * Find or auto-register an account with provisional liability defaults.
     * Called by LedgerPostingService inside its outer posting transaction.
     * Does NOT emit events or audit (no ownership context available per FR-E07-003).
     */
    public function findOrAutoRegister(string $ref, string $currencyCode, string $createdBy): LedgerAccountModel
    {
        return $this->accountRepo->findOrAutoRegister($ref, $currencyCode, $createdBy);
    }
}
