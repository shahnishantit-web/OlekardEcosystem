<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\AgentStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T073 ————————————————————————————————————————————————

test('POST /suspend on active agent returns 200 with suspended status', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.suspend');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'suspend-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/suspend", [
            'reason' => 'Suspicious activity detected.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', AgentStatus::Suspended->value);

    $row = DB::connection('platform')
        ->table('agent.agents')
        ->where('id', $agent->id)
        ->first();

    expect($row->status)->toBe(AgentStatus::Suspended->value);
    expect($row->suspension_reason)->toBe('Suspicious activity detected.');
});

test('POST /suspend on already suspended agent returns 422 AGENT_NOT_ACTIVE', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.suspend');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    // First suspend
    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'suspend-first-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/suspend", ['reason' => 'First suspension.'])
        ->assertStatus(200);

    // Second suspend
    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'suspend-second-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/suspend", ['reason' => 'Second suspension.']);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'AGENT_NOT_ACTIVE');
});

test('POST /suspend emits agent.suspended event in outbox with user_id', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.suspend');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'suspend-event-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/suspend", ['reason' => 'Audit event test.'])
        ->assertStatus(200);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.suspended')
        ->first();

    expect($outbox)->not->toBeNull();
    $payload = json_decode($outbox->payload, true);
    expect($payload['agent_id'])->toBe($agent->id);
    expect($payload['reason'])->toBe('Audit event test.');
});

test('POST /suspend with duplicate Idempotency-Key returns 200 no duplicate event', function () {
    $admin   = $this->makeActorWithPermissions('ADMIN', 'agent.suspend');
    $user    = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result  = $this->createApprovedApplication($user->id, $admin->id);
    $agent   = $result['agent'];
    $idemKey = 'suspend-idem-' . \Ramsey\Uuid\Uuid::uuid7();

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agents/{$agent->id}/suspend", ['reason' => 'First suspension.'])
        ->assertStatus(200);

    $countBefore = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.suspended')
        ->count();

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agents/{$agent->id}/suspend", ['reason' => 'First suspension.'])
        ->assertStatus(200);

    $countAfter = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.suspended')
        ->count();

    expect($countAfter)->toBe($countBefore);
});
