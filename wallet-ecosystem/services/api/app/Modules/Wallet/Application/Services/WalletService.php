<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\CurrencyContract;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Enums\PocketStatus;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Events\WalletClosedEvent;
use App\Modules\Wallet\Domain\Events\WalletFrozenEvent;
use App\Modules\Wallet\Domain\Events\WalletStatusChangedEvent;
use App\Modules\Wallet\Domain\Events\WalletUnfrozenEvent;
use App\Modules\Wallet\Domain\Exceptions\WalletHasBalanceException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException;
use App\Modules\Wallet\Domain\Services\WalletStateMachine;
use App\Modules\Wallet\Infrastructure\Models\CurrencyPocketModel;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use App\Modules\Wallet\Infrastructure\Models\WalletStatusHistoryModel;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentCurrencyPocketRepository;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use App\Modules\Wallet\Infrastructure\Repositories\WalletStatusHistoryRepository;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class WalletService
{
    public function __construct(
        private readonly EloquentWalletRepository            $walletRepository,
        private readonly EloquentCurrencyPocketRepository    $pocketRepository,
        private readonly WalletStatusHistoryRepository       $historyRepository,
        private readonly WalletStateMachine                  $stateMachine,
        private readonly EventPublisherInterface             $eventPublisher,
        private readonly AuditServiceInterface               $auditService,
        private readonly MetricsCollectorInterface           $metrics,
        private readonly WalletBalanceService                $balanceService,
    ) {}

    /**
     * Transition wallet status, persist history entry, publish domain event, and write audit record.
     * All operations are atomic within a single DB transaction.
     */
    public function transitionStatus(
        string $walletId,
        WalletStatus $to,
        string $reason,
        string $actorId,
        string $correlationId,
    ): WalletModel {
        return DB::connection('wallet')->transaction(function () use ($walletId, $to, $reason, $actorId, $correlationId): WalletModel {
            $wallet = WalletModel::on('wallet')->lockForUpdate()->find($walletId);

            if ($wallet === null) {
                throw new WalletNotFoundException($walletId);
            }

            $from = $wallet->status;
            $this->stateMachine->transition($from, $to);

            $beforeState = ['status' => $from->value];

            $wallet->status  = $to;
            $wallet->version = $wallet->version + 1;
            $wallet->save();

            $this->historyRepository->record(
                walletId:      $walletId,
                fromStatus:    $from->value,
                toStatus:      $to->value,
                reason:        $reason,
                actorId:       $actorId,
                correlationId: $correlationId,
            );

            $this->eventPublisher->publish(new WalletStatusChangedEvent(
                walletId:      $walletId,
                fromStatus:    $from->value,
                toStatus:      $to->value,
                reason:        $reason,
                actorId:       $actorId,
                correlationId: $correlationId,
            ));

            $this->auditService->record(new AuditEventData(
                action:        'wallet.status.changed',
                actorType:     'ADMIN',
                resourceType:  'wallet',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $walletId,
                beforeState:   $beforeState,
                afterState:    ['status' => $to->value],
            ));

            return $wallet;
        });
    }

    public function freeze(string $walletId, string $reason, string $actorId, string $correlationId): WalletModel
    {
        $wallet = $this->transitionStatus($walletId, WalletStatus::Frozen, $reason, $actorId, $correlationId);

        $this->eventPublisher->publish(new WalletFrozenEvent(
            walletId:      $walletId,
            reason:        $reason,
            actorId:       $actorId,
            correlationId: $correlationId,
        ));

        $this->auditService->record(new AuditEventData(
            action:        'wallet.frozen',
            actorType:     'ADMIN',
            resourceType:  'wallet',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $walletId,
            afterState:    ['status' => 'frozen', 'reason' => $reason],
        ));

        $this->metrics->increment('wallet.frozen', ['wallet_id' => $walletId]);

        return $wallet;
    }

    public function unfreeze(string $walletId, string $reason, string $actorId, string $correlationId): WalletModel
    {
        $wallet = $this->transitionStatus($walletId, WalletStatus::Active, $reason, $actorId, $correlationId);

        $this->eventPublisher->publish(new WalletUnfrozenEvent(
            walletId:      $walletId,
            reason:        $reason,
            actorId:       $actorId,
            correlationId: $correlationId,
        ));

        $this->auditService->record(new AuditEventData(
            action:        'wallet.unfrozen',
            actorType:     'ADMIN',
            resourceType:  'wallet',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $walletId,
            afterState:    ['status' => 'active', 'reason' => $reason],
        ));

        return $wallet;
    }

    public function close(string $walletId, string $reason, string $actorId, string $correlationId): WalletModel
    {
        $wallet = $this->walletRepository->findById($walletId);
        if ($wallet === null) {
            throw new WalletNotFoundException($walletId);
        }

        // Idempotent: already closed returns existing record
        if ($wallet->status === WalletStatus::Closed) {
            return $wallet;
        }

        // Verify all pockets have zero available_balance and zero held_amount (TBD-E06-003: admin override deferred)
        $pockets = $this->pocketRepository->findAllByWalletId($walletId);
        foreach ($pockets as $pocket) {
            $balance = $this->balanceService->getBalance($walletId, $pocket->currency_code, $correlationId);
            if (($balance['available_balance'] ?? 0) > 0 || ($balance['held_amount'] ?? 0) > 0) {
                throw new WalletHasBalanceException();
            }
        }

        $wallet = $this->transitionStatus($walletId, WalletStatus::Closed, $reason, $actorId, $correlationId);

        // Disable all active pockets
        CurrencyPocketModel::on('wallet')
            ->where('wallet_id', $walletId)
            ->where('status', 'active')
            ->update(['status' => PocketStatus::Disabled->value, 'updated_at' => now()]);

        $this->eventPublisher->publish(new WalletClosedEvent(
            walletId:      $walletId,
            reason:        $reason,
            actorId:       $actorId,
            correlationId: $correlationId,
        ));

        $this->auditService->record(new AuditEventData(
            action:        'wallet.closed',
            actorType:     'ADMIN',
            resourceType:  'wallet',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $walletId,
            afterState:    ['status' => 'closed', 'reason' => $reason],
        ));

        $this->metrics->increment('wallet.closed', ['wallet_id' => $walletId]);

        return $wallet;
    }

    // ── WalletModuleContract delegation methods ───────────────────────────────

    public function isActive(string $walletId): bool
    {
        $wallet = $this->walletRepository->findById($walletId);
        return $wallet !== null && $wallet->status === WalletStatus::Active;
    }

    public function ownerActorId(string $walletId): ?string
    {
        return $this->walletRepository->findById($walletId)?->owner_id;
    }

    public function currency(string $walletId): ?CurrencyContract
    {
        $wallet = $this->walletRepository->findById($walletId);
        if ($wallet === null) {
            return null;
        }

        return \App\Modules\Wallet\Infrastructure\Models\CurrencyModel::on('wallet')
            ->where('is_primary', true)
            ->first()
            ?->toCurrencyContract();
    }
}
