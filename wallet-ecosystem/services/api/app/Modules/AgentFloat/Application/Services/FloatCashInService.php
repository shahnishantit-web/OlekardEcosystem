<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Application\Services;

use App\Modules\AgentFloat\Domain\Enums\FloatTransactionDirection;
use App\Modules\AgentFloat\Domain\Enums\FloatTransactionType;
use App\Modules\AgentFloat\Domain\Events\FloatCashInEvent;
use App\Modules\AgentFloat\Domain\Exceptions\FloatDailyLimitExceededException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatTransactionLimitExceededException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletNotFoundException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletNotActiveException;
use App\Modules\AgentFloat\Domain\Exceptions\InsufficientFloatException;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatTransactionModel;
use App\Modules\Agent\Infrastructure\Models\AgentCustomerRelationshipModel;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class FloatCashInService
{
    public function __construct(
        private readonly LedgerModuleContract        $ledger,
        private readonly WalletModuleContract        $walletModule,
        private readonly FloatAlertService           $alertService,
        private readonly EventPublisherInterface     $events,
        private readonly AuditServiceInterface       $audit,
        private readonly IdempotencyServiceInterface $idempotency,
        private readonly MetricsCollectorInterface   $metrics,
    ) {}

    /**
     * @throws FloatWalletNotFoundException
     * @throws FloatWalletNotActiveException
     * @throws InsufficientFloatException
     * @throws FloatTransactionLimitExceededException
     * @throws FloatDailyLimitExceededException
     */
    public function cashIn(
        string $agentId,
        string $customerId,
        string $customerWalletId,
        int    $amount,
        string $currencyCode,
        string $actorId,
        string $idempotencyKey,
        string $correlationId,
    ): FloatTransactionModel {
        $namespacedKey = "float_cash_in:{$agentId}:{$customerId}:{$idempotencyKey}";

        $existing = $this->idempotency->findCompletedResponse($namespacedKey, $actorId);
        if ($existing !== null) {
            /** @var FloatTransactionModel $txn */
            $txn = FloatTransactionModel::where('idempotency_key', $namespacedKey)->firstOrFail();
            return $txn;
        }

        $lock = $this->idempotency->acquireLock(
            $namespacedKey, $actorId,
            md5($agentId . $customerId . $amount . $currencyCode), 'POST',
            "/v1/agents/{$agentId}/customers/{$customerId}/cash-in", $correlationId
        );

        // Validate agent active
        $agent = AgentModel::where('id', $agentId)->where('status', 'active')->first();
        if ($agent === null) {
            $ex = FloatWalletNotActiveException::suspended($agentId);
            $this->idempotency->recordFailure($lock, $ex);
            throw $ex;
        }

        // Validate float wallet active
        $floatWallet = AgentFloatWalletModel::where('agent_id', $agentId)->first();
        if ($floatWallet === null) {
            $ex = FloatWalletNotFoundException::forAgent($agentId);
            $this->idempotency->recordFailure($lock, $ex);
            throw $ex;
        }

        $walletRecord = WalletModel::find($floatWallet->wallet_id);
        if ($walletRecord !== null && $walletRecord->status->value !== 'active') {
            $ex = FloatWalletNotActiveException::suspended($agentId);
            $this->idempotency->recordFailure($lock, $ex);
            throw $ex;
        }

        // Validate customer is agent-linked
        $relationship = AgentCustomerRelationshipModel::where('agent_id', $agentId)
            ->where('customer_id', $customerId)
            ->first();
        if ($relationship === null) {
            $ex = new \DomainException("Customer [{$customerId}] is not linked to agent [{$agentId}].");
            $this->idempotency->recordFailure($lock, $ex);
            throw $ex;
        }

        // Validate customer wallet
        if (! $this->walletModule->isActive($customerWalletId)) {
            $ex = new \DomainException("Customer wallet [{$customerWalletId}] is not active.");
            $this->idempotency->recordFailure($lock, $ex);
            throw $ex;
        }

        // Obtain customer pocket ledger account reference
        $customerPocketRef = $this->walletModule->getPocketLedgerAccountRef($customerWalletId, $currencyCode);
        if ($customerPocketRef === null) {
            $ex = new \DomainException("No [{$currencyCode}] pocket on customer wallet [{$customerWalletId}].");
            $this->idempotency->recordFailure($lock, $ex);
            throw $ex;
        }

        // Enforce per-transaction and daily limits before acquiring lock
        $limits = FloatLimitModel::where('agent_id', $agentId)->first();
        if ($limits !== null) {
            if (! $limits->per_tx_is_tbd && $limits->per_transaction_limit !== null && $amount > $limits->per_transaction_limit) {
                $ex = FloatTransactionLimitExceededException::exceeded($amount, $limits->per_transaction_limit);
                $this->idempotency->recordFailure($lock, $ex);
                throw $ex;
            }

            if (! $limits->daily_vol_is_tbd && $limits->daily_volume_limit !== null) {
                $dailyUsed = FloatTransactionModel::where('agent_id', $agentId)
                    ->whereDate('created_at', today())
                    ->whereIn('type', ['cash_in', 'cash_out'])
                    ->sum('amount');

                if ($dailyUsed + $amount > $limits->daily_volume_limit) {
                    $ex = FloatDailyLimitExceededException::exceeded((int) $dailyUsed, $limits->daily_volume_limit);
                    $this->idempotency->recordFailure($lock, $ex);
                    throw $ex;
                }
            }
        }

        $txnId  = (string) Uuid::uuid7();
        $txnRef = 'FCI-' . strtoupper(substr(str_replace('-', '', $txnId), 0, 12));

        // Overdraft-safe posting: SELECT FOR UPDATE then balance check then post
        $journalId = DB::connection('platform')->transaction(function () use (
            $floatWallet, $amount, $currencyCode, $txnRef, $correlationId, $txnId,
            $customerPocketRef, $agentId, $customerId
        ): string {
            // Serialize concurrent cash-ins for this float wallet
            DB::connection('platform')->table('agent.float_wallets')
                ->where('id', $floatWallet->id)
                ->lockForUpdate()
                ->first();

            $balance = $this->ledger->getBalance($floatWallet->ledger_account_reference);
            if ($balance < $amount) {
                throw InsufficientFloatException::withBalance($balance, $amount);
            }

            $journalKey = "float_cash_in_journal:{$txnId}";
            return $this->ledger->postEntry([
                'idempotency_key'       => $journalKey,
                'entry_type'            => 'credit',
                'currency_code'         => $currencyCode,
                'transaction_reference' => $txnRef,
                'correlation_id'        => $correlationId,
                'description'           => "Agent float cash-in: agent {$agentId} → customer {$customerId}",
                'lines'                 => [
                    ['ledger_account_reference' => $floatWallet->ledger_account_reference, 'direction' => 'credit', 'amount' => $amount],
                    ['ledger_account_reference' => $customerPocketRef,                      'direction' => 'debit',  'amount' => $amount],
                ],
            ]);
        });

        $txn                  = new FloatTransactionModel();
        $txn->id              = $txnId;
        $txn->agent_id        = $agentId;
        $txn->float_wallet_id = $floatWallet->id;
        $txn->type            = FloatTransactionType::CashIn;
        $txn->amount          = $amount;
        $txn->currency_code   = $currencyCode;
        $txn->direction       = FloatTransactionDirection::Credit;
        $txn->related_party_id   = $customerId;
        $txn->related_party_type = 'customer';
        $txn->reference       = $txnRef;
        $txn->journal_id      = $journalId;
        $txn->idempotency_key = $namespacedKey;
        $txn->status          = 'completed';
        $txn->save();

        $newBalance = $this->ledger->getBalance($floatWallet->ledger_account_reference);
        $this->alertService->checkAndAlert($agentId, $newBalance, $correlationId);

        $this->events->publish(new FloatCashInEvent(
            agentId:            $agentId,
            customerId:         $customerId,
            walletId:           $floatWallet->wallet_id,
            amount:             $amount,
            currencyCode:       $currencyCode,
            journalId:          $journalId,
            floatTransactionId: $txnId,
            correlationId:      $correlationId,
            actorId:            $actorId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'agent_float.cash_in.completed',
            actorType:     'AGENT',
            resourceType:  'agent_float_transaction',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $txnId,
        ));

        $this->metrics->increment('agent_float.cash_in.completed', ['currency' => $currencyCode]);

        $this->idempotency->recordResponse($lock, 201, ['transaction_id' => $txnId]);

        return $txn;
    }
}
