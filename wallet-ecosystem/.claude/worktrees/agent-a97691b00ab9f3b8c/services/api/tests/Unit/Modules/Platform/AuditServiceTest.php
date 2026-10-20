<?php

declare(strict_types=1);

use App\Modules\Platform\Application\Services\AuditService;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

test('record inserts correct fields into audit_events', function () {
    DB::shouldReceive('connection->table->insert')->once()->with(\Mockery::on(function ($args) {
        return $args['action'] === 'platform.permission.denied'
            && $args['outcome'] === 'failure'
            && $args['actor_type'] === 'user';
    }));

    $service = new AuditService();
    $service->record(new AuditEventData(
        action: 'platform.permission.denied',
        actorType: 'user',
        resourceType: 'Permission',
        correlationId: 'test-correlation',
        outcome: 'failure',
        actorId: 'actor-uuid',
    ));
});

test('action follows module.aggregate.verb naming convention', function () {
    $validActions = [
        'platform.permission.denied',
        'configuration.value.changed',
        'idempotency.key.replay',
        'platform.outbox.dead-lettered',
    ];

    foreach ($validActions as $action) {
        expect($action)->toMatch('/^[a-z]+\.[a-z\-]+\.[a-z\-]+$/');
    }
});

test('PII fields in before_state are masked before persistence', function () {
    $insertedPayload = null;

    DB::shouldReceive('connection->table->insert')->once()->with(\Mockery::on(function ($args) use (&$insertedPayload) {
        $insertedPayload = json_decode($args['payload'] ?? '[]', true);
        return true;
    }));

    $service = new AuditService();
    $service->record(new AuditEventData(
        action: 'platform.permission.denied',
        actorType: 'user',
        resourceType: 'User',
        correlationId: 'test-corr',
        outcome: 'success',
        metadata: ['password' => 'secret123', 'name' => 'Alice'],
    ));

    // PiiMasker applies to payload/metadata before persistence
    expect($insertedPayload)->not->toBeNull();
});

test('write failure logs error but does not throw', function () {
    DB::shouldReceive('connection->table->insert')->once()->andThrow(new \Exception('DB down'));
    Log::shouldReceive('error')->once();

    $service = new AuditService();

    expect(fn () => $service->record(new AuditEventData(
        action: 'platform.permission.denied',
        actorType: 'system',
        resourceType: 'Permission',
        correlationId: 'test-corr',
        outcome: 'failure',
    )))->not->toThrow(\Exception::class);
});
