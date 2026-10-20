<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T075 ————————————————————————————————————————————————

test('SC-E12-005: agent.suspended outbox event is created within 1000ms of suspend call', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.suspend');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $startTime = microtime(true);

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'suspend-latency-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/suspend", ['reason' => 'Latency test.'])
        ->assertStatus(200);

    $elapsedMs = (microtime(true) - $startTime) * 1000;

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.suspended')
        ->where('payload', 'like', "%{$agent->id}%")
        ->first();

    expect($outbox)->not->toBeNull();
    expect($elapsedMs)->toBeLessThan(1000);
});
