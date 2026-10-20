<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Application\Services;

use App\Modules\AgentFloat\Domain\Events\FloatWalletCreatedEvent;
use App\Modules\AgentFloat\Domain\Exceptions\AgentNotEligibleForFloatException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletAlreadyExistsException;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class FloatWalletProvisioningService
{
    public function __construct(
        private readonly LedgerModuleContract      $ledger,
        private readonly EventPublisherInterface   $events,
        private readonly AuditServiceInterface     $audit,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    /**
     * @throws AgentNotEligibleForFloatException
     * @throws FloatWalletAlreadyExistsException
     */
    public function provision(
        string $agentId,
        string $currencyCode,
        string $actorId,
        string $idempotencyKey,
        string $correlationId,
    ): AgentFloatWalletModel {
        $namespacedKey = "float_wallet_create:{$agentId}:{$idempotencyKey}";

        $existing = $this->idempotency->findCompletedResponse($namespacedKey, $actorId);
        if ($existing !== null) {
            /** @var AgentFloatWalletModel $wallet */
            $wallet = AgentFloatWalletModel::where('agent_id', $agentId)->firstOrFail();
            return $wallet;
        }

        $lock = $this->idempotency->acquireLock(
            $namespacedKey, $actorId,
            md5($agentId . $currencyCode), 'POST', "/v1/agents/{$agentId}/float-wallet", $correlationId
        );

        // Validate agent is approved and active
        $agent = AgentModel::where('id', $agentId)->where('status', 'active')->first();
        if ($agent === null) {
            $this->idempotency->recordFailure($lock, new AgentNotEligibleForFloatException($agentId, 'not active'));
            throw AgentNotEligibleForFloatException::notApproved($agentId);
        }

        // Check uniqueness
        $floatWallet = AgentFloatWalletModel::where('agent_id', $agentId)->first();
        if ($floatWallet !== null) {
            $this->idempotency->recordFailure($lock, new FloatWalletAlreadyExistsException($agentId));
            throw FloatWalletAlreadyExistsException::forAgent($agentId);
        }

        $walletId             = (string) Uuid::uuid7();
        $ledgerAccountRef     = "agent_float:{$walletId}";
        $walletReference      = 'AFW-' . strtoupper(substr(str_replace('-', '', $walletId), 0, 12));

        // Step 1: Create wallet record on wallet connection
        $walletModel = new WalletModel();
        $walletModel->id               = $walletId;
        $walletModel->wallet_reference = $walletReference;
        $walletModel->owner_id         = $agent->user_id;
        $walletModel->status           = 'active';
        $walletModel->wallet_type      = 'agent_float';
        $walletModel->idempotency_key  = $namespacedKey;
        $walletModel->created_by       = $actorId;
        $walletModel->save();

        // Step 2: Register ledger account (idempotent)
        $this->ledger->registerAccount(
            $ledgerAccountRef,
            'asset',
            $currencyCode,
            $agentId,
            'agent_float',
        );

        // Step 3: Create float_wallets record on platform connection
        // On failure here we attempt to clean up the wallet record (dual-connection pattern)
        try {
            $floatWalletId = (string) Uuid::uuid7();
            $floatWallet   = new AgentFloatWalletModel();
            $floatWallet->id                      = $floatWalletId;
            $floatWallet->agent_id                = $agentId;
            $floatWallet->wallet_id               = $walletId;
            $floatWallet->ledger_account_reference = $ledgerAccountRef;
            $floatWallet->save();
        } catch (\Throwable $e) {
            // Best-effort cleanup of the wallet record on failure
            try {
                WalletModel::where('id', $walletId)->delete();
            } catch (\Throwable) {
                // Suppress secondary cleanup failure; operator alert will fire
            }
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }

        // Publish event and audit
        $this->events->publish(new FloatWalletCreatedEvent(
            agentId:               $agentId,
            walletId:              $walletId,
            ledgerAccountReference: $ledgerAccountRef,
            currencyCode:          $currencyCode,
            correlationId:         $correlationId,
            actorId:               $actorId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'agent_float.wallet.created',
            actorType:     'ADMIN',
            resourceType:  'agent_float_wallet',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $floatWallet->id,
        ));

        $this->idempotency->recordResponse($lock, 201, ['wallet_id' => $walletId]);

        return $floatWallet;
    }
}
