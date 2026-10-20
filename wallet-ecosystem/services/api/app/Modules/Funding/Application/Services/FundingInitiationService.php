<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Events\FundingTransactionInitiated;
use App\Modules\Funding\Domain\Exceptions\FundingLimitExceededException;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Orchestrates the creation of a new FundingTransaction.
 *
 * Responsibilities:
 * - Validate actor owns the wallet
 * - Validate wallet is active with a matching currency pocket
 * - Enforce daily limits (via FundingLimitService) — CC-E08-003 race guard via SELECT FOR UPDATE
 * - Calculate fee (via FundingFeeService)
 * - Persist the transaction in `pending` status
 * - Emit FundingTransactionInitiated event
 * - Record audit event
 * - Emit funding.transaction.initiated metric
 */
final class FundingInitiationService
{
    public function __construct(
        private readonly WalletModuleContract      $wallet,
        private readonly IdentityModuleContract    $identity,
        private readonly FundingFeeService         $feeService,
        private readonly FundingLimitService       $limitService,
        private readonly AuditServiceInterface     $audit,
        private readonly EventPublisherInterface   $events,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * @param array{
     *   customer_id:    string,
     *   wallet_id:      string,
     *   gross_amount:   int,
     *   currency_code:  string,
     *   funding_method: FundingMethod,
     *   correlation_id: string,
     *   ip_address:     string|null,
     * } $request
     *
     * @throws FundingLimitExceededException
     * @throws \RuntimeException
     */
    public function initiate(array $request): FundingTransactionModel
    {
        return DB::connection('platform')->transaction(fn () => $this->initiateWithinTransaction($request));
    }

    private function initiateWithinTransaction(array $request): FundingTransactionModel
    {
        $customerId    = $request['customer_id'];
        $walletId      = $request['wallet_id'];
        $grossAmount   = $request['gross_amount'];
        $currencyCode  = $request['currency_code'];
        $method        = $request['funding_method'];
        $correlationId = $request['correlation_id'];

        // Validate ownership
        if ($this->wallet->ownerActorId($walletId) !== $customerId) {
            throw new \DomainException("Wallet [{$walletId}] does not belong to actor [{$customerId}].");
        }

        // Validate wallet active
        if (! $this->wallet->isActive($walletId)) {
            throw new \DomainException("Wallet [{$walletId}] is not active.");
        }

        // Validate pocket exists
        if (! $this->wallet->pocketExists($walletId, $currencyCode)) {
            throw new \DomainException("No [{$currencyCode}] pocket on wallet [{$walletId}].");
        }

        // Calculate fee
        ['fee_amount' => $feeAmount, 'net_amount' => $netAmount] = $this->feeService->calculate($grossAmount, $method);

        // CC-E08-003: Enforce daily limit inside a transaction with a lock guard
        // to prevent concurrent initiations from race-reading the same daily total.
        $tx = DB::transaction(function () use ($request, $customerId, $grossAmount, $currencyCode, $walletId, $method, $feeAmount, $netAmount, $correlationId): FundingTransactionModel {
            // Lock the customer's row (or just enforce atomically via DB transaction isolation)
            $this->limitService->assertWithinLimits($customerId, $grossAmount, $method, $currencyCode);

            $id = (string) Uuid::uuid7();
            $txRef = $id;

            $model = new FundingTransactionModel();
            $model->id                     = $id;
            $model->customer_id            = $customerId;
            $model->wallet_id              = $walletId;
            $model->pocket_currency_code   = $currencyCode;
            $model->funding_method         = $method;
            $model->status                 = FundingStatus::Pending;
            $model->gross_amount           = $grossAmount;
            $model->fee_amount             = $feeAmount;
            $model->net_amount             = $netAmount;
            $model->transaction_reference  = $txRef;
            $model->idempotency_key        = $request['idempotency_key'];
            $model->initiated_by           = $customerId;
            $model->save();

            return $model;
        });

        // Publish domain event
        $this->events->publish(new FundingTransactionInitiated(
            fundingTransactionId: $tx->id,
            walletId:             $walletId,
            amount:               $grossAmount,
            status:               $tx->status->value,
            occurredAt:           now()->toIso8601String(),
            currencyCode:         $currencyCode,
            fundingMethod:        $method->value,
            correlationId:        $correlationId,
        ));

        // Audit
        $this->audit->record(new AuditEventData(
            action:       'funding.transaction.initiated',
            actorType:    'CUSTOMER',
            resourceType: 'funding_transaction',
            correlationId: $correlationId,
            outcome:      'success',
            actorId:      $customerId,
            resourceId:   $tx->id,
        ));

        // Metric
        $this->metrics->increment('funding.transaction.initiated', [
            'method'   => $method->value,
            'currency' => $currencyCode,
        ]);

        return $tx;
    }
}
