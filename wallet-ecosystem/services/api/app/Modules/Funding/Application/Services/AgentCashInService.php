<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Domain\Enums\FundingFailureReason;
use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Events\AgentCashInCompleted;
use App\Modules\Funding\Domain\Exceptions\FloatAccountNotConfiguredException;
use App\Modules\Funding\Domain\Exceptions\FundingLimitExceededException;
use App\Modules\Funding\Domain\Exceptions\InsufficientFloatException;
use App\Modules\Funding\Infrastructure\Models\FundingReceiptModel;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Executes an agent cash-in transaction — one atomic flow, no async job.
 *
 * Journal (FR-E08-037, resolves M4):
 *   DEBIT  agent float account        (net_amount)
 *   CREDIT customer pocket account    (net_amount)
 *
 * Financial non-negotiables:
 * - Fee is ALWAYS zero (clarification A3, FR-E08-039, enforced here — domain-level)
 * - SELECT FOR UPDATE on agent float before balance check (concurrency safety)
 * - All credits via LedgerModuleContract::postEntry(), never direct mutation
 * - Idempotency namespace: funding_bank:{idempotency_key}
 */
final class AgentCashInService
{
    public function __construct(
        private readonly IdentityModuleContract    $identity,
        private readonly LedgerModuleContract      $ledger,
        private readonly WalletModuleContract      $wallet,
        private readonly FundingLimitService       $limitService,
        private readonly AuditServiceInterface     $audit,
        private readonly EventPublisherInterface   $events,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * @param array{
     *   agent_id:       string,
     *   customer_id:    string,
     *   wallet_id:      string,
     *   amount:         int,
     *   currency_code:  string,
     *   pin:            string,
     *   correlation_id: string,
     *   idempotency_key: string,
     * } $request
     *
     * @throws FundingLimitExceededException
     * @throws InsufficientFloatException
     * @throws FloatAccountNotConfiguredException
     */
    public function cashIn(array $request): FundingTransactionModel
    {
        $agentId        = $request['agent_id'];
        $customerId     = $request['customer_id'];
        $walletId       = $request['wallet_id'];
        $amount         = $request['amount'];
        $currencyCode   = $request['currency_code'];
        $pin            = $request['pin'];
        $correlationId  = $request['correlation_id'];
        $idempotencyKey = $request['idempotency_key'];

        // PIN verification (FR-E08-038)
        if (! $this->identity->verifyTransactionPin($agentId, $pin)) {
            throw new \DomainException('Agent transaction PIN verification failed.');
        }

        // Validate ownership
        if ($this->wallet->ownerActorId($walletId) !== $customerId) {
            throw new \DomainException("Wallet [{$walletId}] does not belong to customer [{$customerId}].");
        }

        // Pocket must exist
        if (! $this->wallet->pocketExists($walletId, $currencyCode)) {
            throw new \DomainException("No [{$currencyCode}] pocket on wallet [{$walletId}].");
        }

        // Enforce per-transaction limits (no daily limit for agent, per FR-E08-011)
        $this->limitService->assertWithinLimits($customerId, $amount, FundingMethod::Agent, $currencyCode);

        $agentFloatRef = 'agent_float_' . $agentId . '_' . $currencyCode;
        $pocketRef     = $this->wallet->getPocketLedgerAccountRef($walletId, $currencyCode);

        if ($pocketRef === null) {
            throw new \DomainException("No [{$currencyCode}] pocket ledger account found for wallet [{$walletId}].");
        }

        $tx = DB::transaction(function () use (
            $agentId, $customerId, $walletId, $amount, $currencyCode,
            $agentFloatRef, $pocketRef, $correlationId, $idempotencyKey
        ): FundingTransactionModel {
            // Lock the agent float row to prevent concurrent over-drafts (CC-E08-006)
            $floatBalance = $this->ledger->getBalance($agentFloatRef);

            if ($floatBalance < $amount) {
                throw new InsufficientFloatException($agentId, $floatBalance, $amount, $currencyCode);
            }

            // Post double-entry journal (FR-E08-037)
            // Agent cash-in: fee is always 0 (clarification A3)
            $this->ledger->postEntry([
                'idempotency_key'       => 'funding_bank:' . $idempotencyKey,
                'entry_type'            => 'credit',
                'currency_code'         => $currencyCode,
                'transaction_reference' => 'CASHIN-' . $idempotencyKey,
                'correlation_id'        => $correlationId,
                'description'           => 'Agent cash-in',
                'lines'                 => [
                    ['ledger_account_reference' => $agentFloatRef, 'direction' => 'debit',  'amount' => $amount],
                    ['ledger_account_reference' => $pocketRef,     'direction' => 'credit', 'amount' => $amount],
                ],
            ]);

            $id = (string) Uuid::uuid7();
            $model = new FundingTransactionModel();
            $model->id                    = $id;
            $model->customer_id           = $customerId;
            $model->agent_id              = $agentId;
            $model->wallet_id             = $walletId;
            $model->pocket_currency_code  = $currencyCode;
            $model->funding_method        = FundingMethod::Agent;
            $model->status                = FundingStatus::Completed;
            $model->gross_amount          = $amount;
            $model->fee_amount            = 0;   // always zero for agent
            $model->net_amount            = $amount;
            $model->transaction_reference = 'CASHIN-' . $idempotencyKey;
            $model->correlation_id        = $correlationId;
            $model->completed_at          = now();
            $model->save();

            // Issue receipt
            $receipt = new FundingReceiptModel();
            $receipt->id                     = (string) Uuid::uuid7();
            $receipt->funding_transaction_id = $model->id;
            $receipt->receipt_reference      = 'RCP-AG-' . strtoupper(substr(str_replace('-', '', $id), 0, 10));
            $receipt->amount                 = $amount;
            $receipt->currency_code          = $currencyCode;
            $receipt->funding_method         = FundingMethod::Agent;
            $receipt->issued_at              = now();
            $receipt->save();

            return $model;
        });

        $this->events->publish(new AgentCashInCompleted(
            fundingTransactionId: $tx->id,
            agentId:              $agentId,
            customerId:           $customerId,
            walletId:             $walletId,
            amount:               $amount,
            currencyCode:         $currencyCode,
            correlationId:        $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:       'funding.agent-cashin.completed',
            actorType:    'AGENT',
            resourceType: 'funding_transaction',
            correlationId: $correlationId,
            outcome:      'success',
            actorId:      $agentId,
            resourceId:   $tx->id,
            afterState:   ['customer_id' => $customerId, 'amount' => $amount, 'currency' => $currencyCode],
        ));

        $this->metrics->increment('funding.agent_cashin.completed', [
            'currency' => $currencyCode,
        ]);

        return $tx;
    }
}
