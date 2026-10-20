<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Enums\WalletLimitType;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Events\WalletCreatedEvent;
use App\Modules\Wallet\Domain\Exceptions\WalletAlreadyExistsException;
use App\Modules\Wallet\Infrastructure\Models\CurrencyPocketModel;
use App\Modules\Wallet\Infrastructure\Models\WalletLimitModel;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use App\Modules\Wallet\Infrastructure\Models\WalletStatusHistoryModel;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class WalletProvisioningService
{
    public function __construct(
        private readonly EloquentWalletRepository  $walletRepository,
        private readonly WalletReferenceGenerator  $referenceGenerator,
        private readonly EventPublisherInterface   $eventPublisher,
        private readonly AuditServiceInterface     $auditService,
    ) {}

    /**
     * Provisions a new wallet for a user.
     * Idempotency key: wallet.provision.{userId}
     *
     * @throws WalletAlreadyExistsException if a non-closed wallet already exists for the user
     */
    public function provisionForUser(string $userId, string $correlationId, ?string $actorId = null): WalletModel
    {
        $idempotencyKey = "wallet.provision.{$userId}";

        $existing = $this->walletRepository->findActiveByOwnerId($userId);
        if ($existing !== null) {
            throw new WalletAlreadyExistsException($userId);
        }

        return DB::connection('wallet')->transaction(function () use ($userId, $correlationId, $actorId, $idempotencyKey): WalletModel {
            $walletId  = (string) Uuid::uuid7();
            $reference = $this->referenceGenerator->generate();
            $systemActor = $actorId ?? 'system';

            // Create wallet row
            $wallet              = new WalletModel();
            $wallet->id          = $walletId;
            $wallet->wallet_reference = $reference;
            $wallet->owner_id    = $userId;
            $wallet->status      = WalletStatus::Pending;
            $wallet->idempotency_key = $idempotencyKey;
            $wallet->version     = 0;
            $wallet->created_by  = $systemActor;
            $wallet->save();

            // Create BWP primary pocket
            $pocket                          = new CurrencyPocketModel();
            $pocket->id                      = (string) Uuid::uuid7();
            $pocket->wallet_id               = $walletId;
            $pocket->currency_code           = 'BWP';
            $pocket->ledger_account_reference = (string) Uuid::uuid7();
            $pocket->status                  = \App\Modules\Wallet\Domain\Enums\PocketStatus::Active;
            $pocket->created_by              = $systemActor;
            $pocket->save();

            // Create initial status history entry (from_status = null for creation)
            $history               = new WalletStatusHistoryModel();
            $history->id           = (string) Uuid::uuid7();
            $history->wallet_id    = $walletId;
            $history->from_status  = null;
            $history->to_status    = 'pending';
            $history->reason       = null;
            $history->actor_id     = $systemActor;
            $history->correlation_id = $correlationId;
            $history->occurred_at  = now();
            $history->save();

            // Create per-wallet wallet_limits for all 5 limit types (all is_tbd=true at baseline)
            $this->seedWalletLimits($walletId, 'BWP', $systemActor);

            // Publish Wallet.Created outbox event (within transaction)
            $event = new WalletCreatedEvent(
                walletId: $walletId,
                ownerId: $userId,
                actorId: $systemActor,
                correlationId: $correlationId,
            );
            $this->eventPublisher->publish($event);

            $this->auditService->record(new AuditEventData(
                action:       'wallet.created',
                actorType:    $actorId ? 'ADMIN' : 'SYSTEM',
                resourceType: 'wallet',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $systemActor,
                resourceId:   $walletId,
                afterState:   ['owner_id' => $userId, 'status' => 'pending'],
            ));

            return $wallet;
        });
    }

    private function seedWalletLimits(string $walletId, string $currencyCode, string $actorId): void
    {
        $now = now()->toIso8601String();

        foreach (WalletLimitType::cases() as $limitType) {
            $limit              = new WalletLimitModel();
            $limit->id          = (string) Uuid::uuid7();
            $limit->wallet_id   = $walletId;
            $limit->currency_code = $currencyCode;
            $limit->limit_type  = $limitType;
            $limit->limit_value = null;
            $limit->is_tbd      = true;
            $limit->effective_from = $now;
            $limit->created_by  = $actorId;
            $limit->updated_by  = $actorId;
            $limit->save();
        }
    }
}
