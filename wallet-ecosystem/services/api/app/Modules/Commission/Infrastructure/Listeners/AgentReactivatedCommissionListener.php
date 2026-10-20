<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Listeners;

use App\Modules\Agent\Domain\Events\AgentReactivatedEvent;
use App\Modules\Commission\Application\Services\CommissionPayoutService;
use App\Modules\Commission\Domain\Enums\CommissionPayoutStatus;
use App\Modules\Commission\Infrastructure\Models\CommissionPayoutModel;
use App\Modules\Platform\Domain\Contracts\ProcessedEventRepositoryInterface;

final class AgentReactivatedCommissionListener
{
    public function __construct(
        private readonly CommissionPayoutService             $payoutService,
        private readonly ProcessedEventRepositoryInterface  $processedEvents,
    ) {}

    public function handle(AgentReactivatedEvent $event): void
    {
        $consumerClass = self::class;
        $eventId       = $event->correlationId;

        if ($this->processedEvents->hasBeenProcessed($eventId, $consumerClass)) {
            return;
        }

        $deferredPayouts = CommissionPayoutModel::where('agent_id', $event->agentId)
            ->where('status', CommissionPayoutStatus::Deferred->value)
            ->get();

        foreach ($deferredPayouts as $payout) {
            $this->payoutService->executeDeferred($payout->id);
        }

        $this->processedEvents->markAsProcessed(
            $eventId,
            $consumerClass,
            new \DateTimeImmutable(),
        );
    }
}
