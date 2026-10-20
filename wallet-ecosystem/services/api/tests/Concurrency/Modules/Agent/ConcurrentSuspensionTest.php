<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\AgentStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T074 ————————————————————————————————————————————————

test('CC-E12-002: concurrent suspension of same agent results in exactly one suspended state', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.suspend');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $idemKey1 = 'cc-suspend-1-' . \Ramsey\Uuid\Uuid::uuid7();
    $idemKey2 = 'cc-suspend-2-' . \Ramsey\Uuid\Uuid::uuid7();

    $r1 = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey1])
        ->postJson("/v1/agents/{$agent->id}/suspend", ['reason' => 'Concurrent suspension 1.']);

    $r2 = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey2])
        ->postJson("/v1/agents/{$agent->id}/suspend", ['reason' => 'Concurrent suspension 2.']);

    $statuses = [$r1->status(), $r2->status()];
    sort($statuses);

    expect($statuses[0])->toBe(200);
    expect($statuses[1])->toBe(422);

    $agentRow = DB::connection('platform')
        ->table('agent.agents')
        ->where('id', $agent->id)
        ->first();

    expect($agentRow->status)->toBe(AgentStatus::Suspended->value);

    $suspendedEventCount = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.suspended')
        ->where('payload', 'like', "%{$agent->id}%")
        ->count();

    expect($suspendedEventCount)->toBe(1);
});
