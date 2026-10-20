<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transaction;

use App\Modules\Platform\Infrastructure\EventBus\InProcessDriver;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use App\Modules\Transaction\Application\EventHandlers\TransactionOutboxSubscriber;
use App\Modules\Transfer\Domain\Events\TransferCompleted;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class TransactionOutboxSubscriberTest extends TestCase
{
    public function test_outbox_restores_the_typed_payment_event_contract(): void
    {
        $original = Event::getFacadeRoot();
        Event::swap(new Dispatcher(new Container()));
        try {
            $source = new TransferCompleted('transfer', 'sender', 'recipient', 'BWP', 100, 0, 100,
                'receipt', '2026-10-19T00:00:00Z', 'correlation');
            TransactionOutboxSubscriber::register([TransferCompleted::class]);
            $received = null;
            Event::listen(TransferCompleted::class, function (TransferCompleted $event) use (&$received): void {
                $received = $event;
            });
            $outbox = new OutboxEventModel();
            $outbox->event_name = $source->eventName();
            $outbox->payload = $source->toArray();
            (new InProcessDriver())->dispatch($outbox);
            self::assertInstanceOf(TransferCompleted::class, $received);
            self::assertSame($source->toArray(), $received->toArray());
        } finally {
            Event::swap($original);
        }
    }

    public function test_service_currency_padding_is_removed_before_wallet_lookup(): void
    {
        $service = new \App\Modules\Service\Infrastructure\Models\ServiceModel();
        $service->setRawAttributes(['currency_code' => 'BWP       ']);
        self::assertSame('BWP', $service->currency_code);
        $payment = new \App\Modules\Service\Infrastructure\Models\ServiceTransactionModel();
        $payment->setRawAttributes(['currency_code' => 'BWP       ']);
        self::assertSame('BWP', $payment->currency_code);
    }
}
