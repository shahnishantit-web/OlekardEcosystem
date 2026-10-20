<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Enums\WalletLimitType;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Events\WalletLimitsConfiguredEvent;
use App\Modules\Wallet\Domain\Exceptions\TransactionLimitExceededException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use App\Modules\Wallet\Infrastructure\Repositories\WalletLimitRepository;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class WalletLimitService
{
    public function __construct(
        private readonly WalletLimitRepository    $limitRepository,
        private readonly EloquentWalletRepository $walletRepository,
        private readonly EventPublisherInterface  $eventPublisher,
        private readonly AuditServiceInterface    $auditService,
    ) {}

    public function getLimitsForWallet(string $walletId): array
    {
        return $this->limitRepository->getByWalletId($walletId);
    }

    /**
     * Update wallet limits. Rejects updates on frozen/closed wallets.
     *
     * @param array<array{currency_code: string, limit_type: string, limit_value: int}> $limitUpdates
     */
    public function updateLimits(
        string $walletId,
        array $limitUpdates,
        string $actorId,
        string $correlationId,
    ): array {
        $wallet = $this->walletRepository->findById($walletId);
        if ($wallet === null) {
            throw new WalletNotFoundException($walletId);
        }

        if (in_array($wallet->status, [WalletStatus::Frozen, WalletStatus::Closed], true)) {
            throw new WalletNotTransactableException($wallet->status);
        }

        $changes  = [];
        $now      = now()->toIso8601String();

        foreach ($limitUpdates as $update) {
            $limitType    = WalletLimitType::from($update['limit_type']);
            $currencyCode = $update['currency_code'];
            $newValue     = $update['limit_value'];

            $existing = $this->limitRepository->findByType($walletId, $currencyCode, $limitType);
            $oldValue = $existing?->limit_value;

            if ($existing === null) {
                $existing              = new \App\Modules\Wallet\Infrastructure\Models\WalletLimitModel();
                $existing->id          = (string) Uuid::uuid7();
                $existing->wallet_id   = $walletId;
                $existing->currency_code = $currencyCode;
                $existing->limit_type  = $limitType;
                $existing->created_by  = $actorId;
            }

            $existing->limit_value    = $newValue;
            $existing->is_tbd         = false;
            $existing->effective_from = $now;
            $existing->updated_by     = $actorId;
            $this->limitRepository->upsert($existing);

            $changes[] = [
                'limitType' => $limitType->value,
                'oldValue'  => $oldValue,
                'newValue'  => $newValue,
            ];
        }

        $this->eventPublisher->publish(new WalletLimitsConfiguredEvent(
            walletId:      $walletId,
            changes:       $changes,
            actorId:       $actorId,
            correlationId: $correlationId,
        ));

        $this->auditService->record(new AuditEventData(
            action:        'wallet.limits.configured',
            actorType:     'ADMIN',
            resourceType:  'wallet',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $walletId,
            afterState:    ['changes' => $changes],
        ));

        return $this->limitRepository->getByWalletId($walletId);
    }

    /**
     * Enforce a specific limit type for a pending debit.
     * MUST skip any limit row where is_tbd = true — a TBD limit is treated as unlimited.
     *
     * @throws TransactionLimitExceededException
     */
    public function enforceLimits(
        string $walletId,
        string $currencyCode,
        WalletLimitType $type,
        int $amount,
    ): void {
        $limit = $this->limitRepository->findByType($walletId, $currencyCode, $type);

        if ($limit === null) {
            return;
        }

        // Skip TBD limits — treat as unlimited (no enforcement)
        if ($limit->is_tbd) {
            Log::debug('wallet.limit.skip.tbd', [
                'wallet_id'    => $walletId,
                'currency'     => $currencyCode,
                'limit_type'   => $type->value,
            ]);
            return;
        }

        // NULL limit_value means unlimited; never treat as zero
        if ($limit->limit_value === null) {
            return;
        }

        if ($amount > $limit->limit_value) {
            throw new TransactionLimitExceededException($type, $amount, $limit->limit_value);
        }
    }
}
