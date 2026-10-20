<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Events\FundingTransactionCancelled;
use App\Modules\Funding\Domain\Exceptions\FundingTransactionNotFoundException;
use App\Modules\Funding\Domain\Exceptions\TransactionNotModifiableException;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a FundingTransaction that is in a cancellable status.
 *
 * Cancellable statuses (from FundingStatus::isCancellable()):
 *   - pending, awaiting_match (clarification A2: awaiting_match IS cancellable)
 * Non-cancellable: processing, completed, failed, cancelled
 */
final class FundingCancellationService
{
    public function __construct(
        private readonly AuditServiceInterface     $audit,
        private readonly EventPublisherInterface   $events,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * @param array{
     *   transaction_id: string,
     *   customer_id:    string,
     *   correlation_id: string,
     * } $request
     *
     * @throws FundingTransactionNotFoundException
     * @throws TransactionNotModifiableException
     */
    public function cancel(array $request): FundingTransactionModel
    {
        $transactionId = $request['transaction_id'];
        $customerId    = $request['customer_id'];
        $correlationId = $request['correlation_id'];

        $tx = DB::transaction(function () use ($transactionId, $customerId): FundingTransactionModel {
            $tx = FundingTransactionModel::where('id', $transactionId)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            if ($tx === null) {
                throw FundingTransactionNotFoundException::forId($transactionId);
            }

            if (! $tx->status->isCancellable()) {
                throw TransactionNotModifiableException::notCancellable($transactionId, $tx->status->value);
            }

            $tx->status = FundingStatus::Cancelled;
            $tx->save();

            return $tx;
        });

        $this->events->publish(new FundingTransactionCancelled(
            fundingTransactionId: $tx->id,
            customerId:           $customerId,
            correlationId:        $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:       'funding.transaction.cancelled',
            actorType:    'CUSTOMER',
            resourceType: 'funding_transaction',
            correlationId: $correlationId,
            outcome:      'success',
            actorId:      $customerId,
            resourceId:   $tx->id,
        ));

        $this->metrics->increment('funding.transaction.cancelled', [
            'method' => $tx->funding_method->value,
        ]);

        return $tx;
    }
}
