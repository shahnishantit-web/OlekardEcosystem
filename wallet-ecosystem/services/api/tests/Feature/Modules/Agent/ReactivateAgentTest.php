<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\AgentStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T108 ————————————————————————————————————————————————

function suspendAgent(string $agentId, string $adminId): void
{
    DB::connection('platform')
        ->table('agent.agents')
        ->where('id', $agentId)
        ->update([
            'status'           => AgentStatus::Suspended->value,
            'suspended_by'     => $adminId,
            'suspension_reason' => 'Test suspension.',
            'suspended_at'     => now(),
        ]);
}

test('POST /reactivate on suspended agent returns 200 with active status', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.reactivate');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    suspendAgent($agent->id, $admin->id);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'reactivate-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/reactivate");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', AgentStatus::Active->value);

    $row = DB::connection('platform')
        ->table('agent.agents')
        ->where('id', $agent->id)
        ->first();

    expect($row->status)->toBe(AgentStatus::Active->value);
    expect($row->suspended_at)->toBeNull();
});

test('POST /reactivate on already active agent returns 422 AGENT_NOT_SUSPENDED', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.reactivate');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'reactivate-active-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/reactivate");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'AGENT_NOT_SUSPENDED');
});

test('POST /reactivate emits agent.reactivated event in outbox', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.reactivate');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    suspendAgent($agent->id, $admin->id);

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'reactivate-event-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/reactivate")
        ->assertStatus(200);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.reactivated')
        ->first();

    expect($outbox)->not->toBeNull();
    $payload = json_decode($outbox->payload, true);
    expect($payload['agent_id'])->toBe($agent->id);
});

test('POST /reactivate with duplicate Idempotency-Key returns 200 no duplicate transition', function () {
    $admin   = $this->makeActorWithPermissions('ADMIN', 'agent.reactivate');
    $user    = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result  = $this->createApprovedApplication($user->id, $admin->id);
    $agent   = $result['agent'];
    $idemKey = 'reactivate-idem-' . \Ramsey\Uuid\Uuid::uuid7();

    suspendAgent($agent->id, $admin->id);

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agents/{$agent->id}/reactivate")
        ->assertStatus(200);

    $countBefore = DB::connection('platform')
        ->table('agent.agent_status_history')
        ->where('agent_id', $agent->id)
        ->where('new_status', AgentStatus::Active->value)
        ->count();

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agents/{$agent->id}/reactivate")
        ->assertStatus(200);

    $countAfter = DB::connection('platform')
        ->table('agent.agent_status_history')
        ->where('agent_id', $agent->id)
        ->where('new_status', AgentStatus::Active->value)
        ->count();

    expect($countAfter)->toBe($countBefore);
});
