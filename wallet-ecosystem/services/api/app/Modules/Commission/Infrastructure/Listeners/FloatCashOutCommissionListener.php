<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Listeners;

use App\Modules\AgentFloat\Domain\Events\FloatCashOutEvent;
use App\Modules\Commission\Application\Services\CommissionCalculationService;
use App\Modules\Commission\Infrastructure\Repositories\CommissionProcessedEventRepository;

final class FloatCashOutCommissionListener
{
    public function __construct(
        private readonly CommissionCalculationService        $calculationService,
        private readonly CommissionProcessedEventRepository  $processedEvents,
    ) {}

    public function handle(FloatCashOutEvent $event): void
    {
        $consumerClass = self::class;
        $eventId       = $event->correlationId();

        if ($this->processedEvents->hasBeenProcessed($eventId, $consumerClass)) {
            return;
        }

        $this->calculationService->calculate(
            agentId:         $event->agentId,
            transactionId:   $event->floatTransactionId,
            transactionType: 'cash_out',
            amount:          $event->amount,
            currencyCode:    $event->currencyCode,
            walletId:        $event->walletId,
            correlationId:   $event->correlationId(),
        );

        $this->processedEvents->markAsProcessed(
            $eventId,
            $consumerClass,
            new \DateTimeImmutable(),
        );
    }
}
