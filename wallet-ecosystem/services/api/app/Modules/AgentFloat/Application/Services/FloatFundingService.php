<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Application\Services;

use App\Modules\AgentFloat\Domain\Enums\FloatTransactionDirection;
use App\Modules\AgentFloat\Domain\Enums\FloatTransactionType;
use App\Modules\AgentFloat\Domain\Events\FloatFundedEvent;
use App\Modules\AgentFloat\Domain\Exceptions\FloatFundingAccountNotConfiguredException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatLimitExceededException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletNotFoundException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletNotActiveException;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use Ramsey\Uuid\Uuid;

final class FloatFundingService
{
    public function __construct(
        private readonly LedgerModuleContract        $ledger,
        private readonly ConfigurationServiceInterface $config,
        private readonly FloatAlertService           $alertService,
        private readonly EventPublisherInterface     $events,
        private readonly AuditServiceInterface       $audit,
        private readonly IdempotencyServiceInterface $idempotency,
        private readonly MetricsCollectorInterface   $metrics,
    ) {}

    /**
     * @throws FloatWalletNotFoundException
     * @throws FloatWalletNotActiveException
     * @throws FloatFundingAccountNotConfiguredException
     * @throws FloatLimitExceededException
     */
    public function fund(
        string $agentId,
        int    $amount,
        string $currencyCode,
        string $actorId,
        string $idempotencyKey,
        string $correlationId,
    ): FloatTransactionModel {
        $namespacedKey = "float_fund:{$agentId}:{$idempotencyKey}";

        $existing = $this->idempotency->findCompletedResponse($namespacedKey, $actorId);
        if ($existing !== null) {
            /** @var FloatTransactionModel $txn */
            $txn = FloatTransactionModel::where('idempotency_key', $namespacedKey)->firstOrFail();
            return $txn;
        }

        $lock = $this->idempotency->acquireLock(
            $namespacedKey, $actorId,
            md5($agentId . $amount . $currencyCode), 'POST',
            "/v1/agents/{$agentId}/float-wallet/fund", $correlationId
        );

        $floatWallet = AgentFloatWalletModel::where('agent_id', $agentId)->first();
        if ($floatWallet === null) {
            $this->idempotency->recordFailure($lock, new FloatWalletNotFoundException($agentId));
            throw FloatWalletNotFoundException::forAgent($agentId);
        }

        $walletRecord = WalletModel::find($floatWallet->wallet_id);
        $walletStatus = $walletRecord?->status;
        if ($walletStatus !== null && $walletStatus->value !== 'active') {
            $ex = FloatWalletNotActiveException::suspended($agentId);
            $this->idempotency->recordFailure($lock, $ex);
            throw $ex;
        }

        // Read platform funding account — TBD-E13-001
        $platformFundingRef = $this->config->get("ledger.agent_float_funding_account.{$currencyCode}");
        if ($platformFundingRef === null) {
            $ex = FloatFundingAccountNotConfiguredException::forKey("ledger.agent_float_funding_account.{$currencyCode}");
            $this->idempotency->recordFailure($lock, $ex);
            throw $ex;
        }

        // Enforce max_balance limit if set
        $limits = FloatLimitModel::where('agent_id', $agentId)->first();
        if ($limits !== null && ! $limits->max_balance_is_tbd && $limits->max_balance !== null) {
            $currentBalance = $this->ledger->getBalance($floatWallet->ledger_account_reference);
            if ($currentBalance + $amount > $limits->max_balance) {
                $ex = FloatLimitExceededException::maxBalance($currentBalance, $amount, $limits->max_balance);
                $this->idempotency->recordFailure($lock, $ex);
                throw $ex;
            }
        }

        $txnId    = (string) Uuid::uuid7();
        $txnRef   = 'FLF-' . strtoupper(substr(str_replace('-', '', $txnId), 0, 12));
        $journalKey = "float_fund_journal:{$txnId}";

        $journalId = $this->ledger->postEntry([
            'idempotency_key'       => $journalKey,
            'entry_type'            => 'debit',
            'currency_code'         => $currencyCode,
            'transaction_reference' => $txnRef,
            'correlation_id'        => $correlationId,
            'description'           => "Agent float funding for agent {$agentId}",
            'lines'                 => [
                ['ledger_account_reference' => $floatWallet->ledger_account_reference, 'direction' => 'debit',  'amount' => $amount],
                ['ledger_account_reference' => $platformFundingRef,                     'direction' => 'credit', 'amount' => $amount],
            ],
        ]);

        $txn                  = new FloatTransactionModel();
        $txn->id              = $txnId;
        $txn->agent_id        = $agentId;
        $txn->float_wallet_id = $floatWallet->id;
        $txn->type            = FloatTransactionType::Funding;
        $txn->amount          = $amount;
        $txn->currency_code   = $currencyCode;
        $txn->direction       = FloatTransactionDirection::Debit;
        $txn->related_party_id   = $actorId;
        $txn->related_party_type = 'admin';
        $txn->reference       = $txnRef;
        $txn->journal_id      = $journalId;
        $txn->idempotency_key = $namespacedKey;
        $txn->status          = 'completed';
        $txn->save();

        $newBalance = $this->ledger->getBalance($floatWallet->ledger_account_reference);
        $this->alertService->checkAndAlert($agentId, $newBalance, $correlationId);

        $this->events->publish(new FloatFundedEvent(
            agentId:            $agentId,
            walletId:           $floatWallet->wallet_id,
            amount:             $amount,
            currencyCode:       $currencyCode,
            journalId:          $journalId,
            floatTransactionId: $txnId,
            correlationId:      $correlationId,
            actorId:            $actorId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'agent_float.wallet.funded',
            actorType:     'ADMIN',
            resourceType:  'agent_float_transaction',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $txnId,
        ));

        $this->metrics->increment('agent_float.fund.completed', ['currency' => $currencyCode]);

        $this->idempotency->recordResponse($lock, 201, ['transaction_id' => $txnId]);

        return $txn;
    }
}
