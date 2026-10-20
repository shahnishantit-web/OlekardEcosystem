<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Application\Jobs\FundingInitiationJob;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Events\FundingTransactionConfirmed;
use App\Modules\Funding\Domain\Exceptions\FeeChangedException;
use App\Modules\Funding\Domain\Exceptions\FundingTransactionNotFoundException;
use App\Modules\Funding\Domain\Exceptions\TransactionNotModifiableException;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;

/**
 * Confirms a pending FundingTransaction:
 * 1. Verifies the customer's transaction PIN.
 * 2. Recalculates fee — if changed, throws FeeChangedException (transaction stays pending).
 * 3. Transitions status → processing (or awaiting_match for bank_transfer).
 * 4. For methods that call an external provider: dispatches FundingInitiationJob.
 * 5. For bank_transfer: generates a bank reference code.
 */
final class FundingConfirmationService
{
    public function __construct(
        private readonly IdentityModuleContract    $identity,
        private readonly FundingFeeService         $feeService,
        private readonly BankReferenceGenerator    $bankRefGenerator,
        private readonly AuditServiceInterface     $audit,
        private readonly EventPublisherInterface   $events,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * @param array{
     *   transaction_id: string,
     *   customer_id:    string,
     *   pin:            string,
     *   accepted_fee:   int,
     *   correlation_id: string,
     * } $request
     *
     * @throws FundingTransactionNotFoundException
     * @throws TransactionNotModifiableException
     * @throws \RuntimeException PIN verification failed
     * @throws FeeChangedException
     */
    public function confirm(array $request): FundingTransactionModel
    {
        return DB::connection('platform')->transaction(fn () => $this->confirmWithinTransaction($request));
    }

    private function confirmWithinTransaction(array $request): FundingTransactionModel
    {
        $transactionId = $request['transaction_id'];
        $customerId    = $request['customer_id'];
        $pin           = $request['pin'];
        $acceptedFee   = $request['accepted_fee'];
        $correlationId = $request['correlation_id'];

        $tx = FundingTransactionModel::where('id', $transactionId)
            ->where('customer_id', $customerId)
            ->first();

        if ($tx === null) {
            throw FundingTransactionNotFoundException::forId($transactionId);
        }

        if ($tx->status !== FundingStatus::Pending) {
            throw TransactionNotModifiableException::notPending($transactionId, $tx->status->value);
        }

        // PIN verification — throws if invalid
        if (! $this->identity->verifyTransactionPin($customerId, $pin)) {
            throw new \DomainException('Transaction PIN verification failed.');
        }

        // Fee recalculation guard
        ['fee_amount' => $currentFee, 'net_amount' => $currentNet] = $this->feeService->calculate(
            $tx->gross_amount,
            $tx->funding_method,
        );

        if ($currentFee !== $acceptedFee) {
            // Update the stored fee so the next confirm attempt sees the correct value
            $tx->fee_amount = $currentFee;
            $tx->net_amount = $currentNet;
            $tx->save();

            throw new FeeChangedException($currentFee, $currentNet);
        }

        $tx = DB::transaction(function () use ($tx, $correlationId): FundingTransactionModel {
            $tx->refresh();

            if ($tx->status !== FundingStatus::Pending) {
                throw TransactionNotModifiableException::notPending($tx->id, $tx->status->value);
            }

            // Determine next status and bank reference
            if ($tx->funding_method === \App\Modules\Funding\Domain\Enums\FundingMethod::BankTransfer) {
                $bankRef = $this->bankRefGenerator->generate();
                $tx->bank_reference_code       = $bankRef;
                $tx->bank_reference_expires_at = now()->addHours(72);
                $tx->status                    = FundingStatus::AwaitingMatch;
            } else {
                $tx->status = FundingStatus::Processing;
            }

            $tx->save();
            return $tx;
        });

        // Dispatch async job for provider-initiated methods (not bank transfer)
        if ($tx->status === FundingStatus::Processing) {
            FundingInitiationJob::dispatch($tx->id, $correlationId);
        }

        // Publish event
        $this->events->publish(new FundingTransactionConfirmed(
            fundingTransactionId: $tx->id,
            walletId:             $tx->wallet_id,
            currencyCode:         $tx->pocket_currency_code,
            amount:               $tx->gross_amount,
            occurredAt:           now()->toIso8601String(),
            status:               $tx->status->value,
            correlationId:        $correlationId,
        ));

        // Audit
        $this->audit->record(new AuditEventData(
            action:       'funding.transaction.confirmed',
            actorType:    'CUSTOMER',
            resourceType: 'funding_transaction',
            correlationId: $correlationId,
            outcome:      'success',
            actorId:      $customerId,
            resourceId:   $tx->id,
            afterState:   ['status' => $tx->status->value],
        ));

        $this->metrics->increment('funding.transaction.confirmed', [
            'method' => $tx->funding_method->value,
        ]);

        return $tx;
    }
}
