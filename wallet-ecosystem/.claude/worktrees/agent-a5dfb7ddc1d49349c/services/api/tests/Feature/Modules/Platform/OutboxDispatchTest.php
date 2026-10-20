<?php

declare(strict_types=1);

use App\Jobs\OutboxDispatcherJob;
use App\Jobs\OutboxStuckProcessingCleanupJob;
use App\Modules\Platform\Application\Services\AuditService;
use App\Modules\Platform\Application\Services\ConfigurationService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\PlatformTestCase;

uses(PlatformTestCase::class);

beforeEach(function () {
    DB::connection('platform')->table('platform.outbox_events')->truncate();
    DB::connection('platform')->table('platform.audit_events')->truncate();
});

// Concrete test event for Outbox tests
class TestIntegrationEvent extends BaseIntegrationEvent
{
    public function __construct(private readonly string $testPayload = 'test')
    {
        parent::__construct(
            aggregateId: (string) Uuid::uuid7(),
            actorId: null,
            correlationId: (string) Uuid::uuid7(),
        );
    }

    public function eventName(): string    { return 'Platform.Foundation.TestDispatched'; }
    public function eventVersion(): string { return '1.0'; }
    public function aggregateType(): string { return 'Test'; }
    public function payload(): array       { return ['test_payload' => $this->testPayload]; }
}

test('FINANCIAL SAFETY: publish inside transaction + rollback → zero outbox rows', function () {
    /** @var OutboxEventPublisher $publisher */
    $publisher = app(OutboxEventPublisher::class);
    $event     = new TestIntegrationEvent('rollback-test');

    DB::connection('platform')->beginTransaction();
    $publisher->publish($event);
    DB::connection('platform')->rollBack();

    $count = DB::connection('platform')->table('platform.outbox_events')->count();
    expect($count)->toBe(0);
});

test('publish inside transaction + commit → outbox row created with status pending', function () {
    /** @var OutboxEventPublisher $publisher */
    $publisher = app(OutboxEventPublisher::class);
    $event     = new TestIntegrationEvent('commit-test');

    DB::connection('platform')->beginTransaction();
    $publisher->publish($event);
    DB::connection('platform')->commit();

    $row = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'Platform.Foundation.TestDispatched')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->status)->toBe('pending');
});

test('publish outside transaction throws LogicException', function () {
    // DatabaseTransactions trait wraps each test in a transaction so
    // transactionLevel() is always >= 1. Temporarily commit that wrapper
    // transaction so we can test the no-transaction guard.
    DB::connection('platform')->commit();

    /** @var OutboxEventPublisher $publisher */
    $publisher = app(OutboxEventPublisher::class);
    $event     = new TestIntegrationEvent('no-tx');

    try {
        expect(fn () => $publisher->publish($event))
            ->toThrow(\LogicException::class);
    } finally {
        // Re-open a transaction so DatabaseTransactions tearDown can roll back.
        DB::connection('platform')->beginTransaction();
    }
});

test('OutboxDispatcherJob processes pending events to delivered', function () {
    $eventId = (string) Uuid::uuid7();

    DB::connection('platform')->table('platform.outbox_events')->insert([
        'id'              => $eventId,
        'aggregate_id'    => (string) Uuid::uuid7(),
        'aggregate_type'  => 'Test',
        'actor_id'        => null,
        'correlation_id'  => (string) Uuid::uuid7(),
        'causation_id'    => null,
        'event_name'      => 'Platform.Test.Dispatched',
        'event_version'   => '1.0',
        'payload'         => json_encode(['x' => 1]),
        'metadata'        => json_encode([]),
        'status'          => 'pending',
        'attempts'        => 0,
        'max_attempts'    => 5,
        'next_attempt_at' => null,
        'created_at'      => now(),
    ]);

    (new OutboxDispatcherJob())->handle(
        app(\App\Modules\Platform\Infrastructure\EventBus\EventBusDriverInterface::class),
        app(AuditServiceInterface::class)
    );

    $row = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('id', $eventId)
        ->first();

    expect($row->status)->toBe('delivered');
    expect($row->delivered_at)->not->toBeNull();
});

test('stuck-processing cleanup resets rows without resetting attempts', function () {
    $eventId = (string) Uuid::uuid7();

    DB::connection('platform')->table('platform.outbox_events')->insert([
        'id'                    => $eventId,
        'aggregate_id'          => (string) Uuid::uuid7(),
        'aggregate_type'        => 'Test',
        'actor_id'              => null,
        'correlation_id'        => (string) Uuid::uuid7(),
        'causation_id'          => null,
        'event_name'            => 'Platform.Test.Stuck',
        'event_version'         => '1.0',
        'payload'               => json_encode([]),
        'metadata'              => json_encode([]),
        'status'                => 'processing',
        'attempts'              => 2,
        'max_attempts'          => 5,
        'processing_started_at' => now()->subMinutes(10),
        'next_attempt_at'       => null,
        'created_at'            => now()->subMinutes(15),
    ]);

    (new OutboxStuckProcessingCleanupJob(app(ConfigurationService::class)))->handle();

    $row = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('id', $eventId)
        ->first();

    expect($row->status)->toBe('pending');
    expect($row->processing_started_at)->toBeNull();
    expect($row->attempts)->toBe(2); // NOT reset
});

test('dead-letter after max_attempts: status=dead_letter and AuditService receives platform.outbox.dead-lettered', function () {
    $auditCalls = [];
    $auditSpy   = new class($auditCalls) implements AuditServiceInterface {
        public function __construct(private array &$calls) {}
        public function record(\App\Modules\Platform\Domain\ValueObjects\AuditEventData $event): void
        {
            $this->calls[] = $event->action;
        }
    };

    $this->app->instance(AuditServiceInterface::class, $auditSpy);

    $eventId = (string) Uuid::uuid7();

    DB::connection('platform')->table('platform.outbox_events')->insert([
        'id'              => $eventId,
        'aggregate_id'    => (string) Uuid::uuid7(),
        'aggregate_type'  => 'Test',
        'actor_id'        => null,
        'correlation_id'  => (string) Uuid::uuid7(),
        'causation_id'    => null,
        'event_name'      => 'Platform.Test.DeadLetter',
        'event_version'   => '1.0',
        'payload'         => json_encode([]),
        'metadata'        => json_encode([]),
        'status'          => 'pending',
        'attempts'        => 4,
        'max_attempts'    => 5,
        'next_attempt_at' => null,
        'created_at'      => now(),
    ]);

    // Force failure by making EventBusDriver throw
    $failingDriver = new class implements \App\Modules\Platform\Infrastructure\EventBus\EventBusDriverInterface {
        public function dispatch(\App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel $event): void
        {
            throw new \RuntimeException('Simulated dispatch failure');
        }
    };

    (new OutboxDispatcherJob())->handle($failingDriver, $auditSpy);

    $row = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('id', $eventId)
        ->first();

    expect($row->status)->toBe('dead_letter');
    expect($auditCalls)->toContain('platform.outbox.dead-lettered');
});
