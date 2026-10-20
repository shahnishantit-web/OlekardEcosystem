<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Listeners;

use App\Modules\Agent\Domain\Events\AgentSuspendedEvent;
use App\Modules\AgentFloat\Domain\Events\FloatWalletStatusChangedEvent;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;

final class AgentSuspendedFloatListener
{
    public function __construct(
        private readonly EventPublisherInterface $events,
        private readonly AuditServiceInterface   $audit,
    ) {}

    public function handle(AgentSuspendedEvent $event): void
    {
        $agentId       = $event->agentId;
        $correlationId = $event->correlationId;

        $floatWallet = AgentFloatWalletModel::where('agent_id', $agentId)->first();
        if ($floatWallet === null) {
            return;
        }

        $walletRecord = WalletModel::find($floatWallet->wallet_id);
        if ($walletRecord === null) {
            return;
        }

        $oldStatus = $walletRecord->status->value;
        $walletRecord->status = WalletStatus::Suspended;
        $walletRecord->save();

        $this->events->publish(new FloatWalletStatusChangedEvent(
            agentId:      $agentId,
            walletId:     $floatWallet->wallet_id,
            oldStatus:    (string) $oldStatus,
            newStatus:    'suspended',
            triggeredBy:  $event->suspendedBy,
            correlationId: $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'agent_float.wallet.suspended',
            actorType:     'SYSTEM',
            resourceType:  'agent_float_wallet',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $event->suspendedBy,
            resourceId:    $floatWallet->id,
        ));
    }
}
