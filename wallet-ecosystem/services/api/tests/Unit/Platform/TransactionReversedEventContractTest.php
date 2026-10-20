<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use App\Modules\Platform\Domain\Events\TransactionReversedEvent;
use PHPUnit\Framework\TestCase;

final class TransactionReversedEventContractTest extends TestCase
{
    public function test_reversal_event_can_be_loaded_and_published_through_the_existing_contract(): void
    {
        $event = new TransactionReversedEvent('transaction-1', 'funding', 'wallet-1', 12345, 'BWP', 'correlation-1', '2026-10-15T00:00:00+00:00');
        $this->assertInstanceOf(IntegrationEventInterface::class, $event);
        $this->assertSame('Platform.TransactionReversed', $event->eventName());
        $this->assertSame('correlation-1', $event->correlationId());
        $this->assertSame('2026-10-15T00:00:00+00:00', $event->occurredAt());
        $this->assertSame(['transaction_id' => 'transaction-1', 'module_type' => 'funding', 'wallet_id' => 'wallet-1', 'amount' => 12345, 'currency' => 'BWP', 'correlation_id' => 'correlation-1', 'reversed_at' => '2026-10-15T00:00:00+00:00'], $event->toArray());
        $this->assertSame('transaction-1', $event->transactionId);
    }
}
