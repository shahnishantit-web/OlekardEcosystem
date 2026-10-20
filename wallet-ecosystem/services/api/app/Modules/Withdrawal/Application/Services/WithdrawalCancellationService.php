<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCancelled;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalNotCancellableException;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;

final class WithdrawalCancellationService
{
    public function __construct(
        private readonly WithdrawalMetricsService $metrics,
        private readonly AuditServiceInterface    $audit,
        private readonly EventPublisherInterface  $events,
    ) {}

    /**
     * @throws WithdrawalNotCancellableException
     * @throws \DomainException  — withdrawal not found or not owned
     */
    public function cancel(
        string $withdrawalId,
        string $customerId,
        string $correlationId,
    ): WithdrawalTransactionModel {
        $tx = WithdrawalTransactionModel::where('id', $withdrawalId)
            ->where('customer_id', $customerId)
            ->first();

        if ($tx === null) {
            throw new \DomainException("Withdrawal transaction [{$withdrawalId}] not found.");
        }

        if (! $tx->isCancellable()) {
            throw new WithdrawalNotCancellableException($tx->status->value);
        }

        $tx->status    = WithdrawalStatus::Cancelled;
        $tx->failed_at = now();
        $tx->save();

        $this->events->publish(new WithdrawalCancelled(
            withdrawalTransactionId: $tx->id,
            walletId:                $tx->wallet_id,
            currencyCode:            $tx->pocket_currency_code,
            grossAmount:             $tx->gross_amount,
            channel:                 $tx->channel->value,
            occurredAt:              now()->toIso8601String(),
            correlationId:           $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'withdrawal.transaction.cancelled',
            actorId:       $customerId,
            actorType:     'CUSTOMER',
            resourceType:  'withdrawal_transaction',
            resourceId:    $tx->id,
            correlationId: $correlationId,
            outcome:       'success',
            afterState:    ['status' => WithdrawalStatus::Cancelled->value],
        ));

        $this->metrics->recordCancelled($tx->channel->value);

        return $tx;
    }
}
