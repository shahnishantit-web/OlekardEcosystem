<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Domain\Events\TransferCancelled;
use App\Modules\Transfer\Domain\Exceptions\TransferNotCancellableException;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;

final class TransferCancellationService
{
    public function __construct(
        private readonly AuditServiceInterface   $audit,
        private readonly EventPublisherInterface $events,
        private readonly TransferMetricsService  $metrics,
    ) {}

    /**
     * @throws TransferNotCancellableException
     * @throws \DomainException on ownership failure
     */
    public function cancel(
        string $transferId,
        string $customerId,
        string $correlationId,
    ): TransferTransactionModel {
        $tx = TransferTransactionModel::find($transferId);

        if ($tx === null || $tx->sender_customer_id !== $customerId) {
            throw new \DomainException("Transfer [{$transferId}] not found.");
        }

        if (! $tx->status->isCancellable()) {
            throw TransferNotCancellableException::forId($transferId, $tx->status->value);
        }

        $tx->status       = TransferStatus::Cancelled;
        $tx->cancelled_at = now();
        $tx->save();

        $this->events->publish(new TransferCancelled(
            transferId:     $tx->id,
            senderWalletId: $tx->sender_wallet_id,
            currencyCode:   $tx->currency_code,
            grossAmount:    $tx->gross_amount,
            occurredAt:     now()->toIso8601String(),
            correlationId:  $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'transfer.cancelled',
            actorId:       $customerId,
            actorType:     'CUSTOMER',
            resourceType:  'transfer_transaction',
            resourceId:    $tx->id,
            correlationId: $correlationId,
            outcome:       'success',
            afterState:    ['status' => TransferStatus::Cancelled->value],
        ));

        $this->metrics->transferCancelled($tx->currency_code);

        return $tx;
    }
}
