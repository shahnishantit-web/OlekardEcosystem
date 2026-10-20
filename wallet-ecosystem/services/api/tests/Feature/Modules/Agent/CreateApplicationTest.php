<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T031 ————————————————————————————————————————————————

test('POST /agent-applications returns 201 with draft status for valid request', function () {
    $user = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.create');

    $response = $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'test-create-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson('/v1/agent-applications', ['agent_type' => 'individual']);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.agent_type', 'individual');

    $appId = $response->json('data.id');
    $row   = DB::connection('platform')->table('agent.agent_applications')->where('id', $appId)->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('draft');
    expect($row->user_id)->toBe($user->id);
});

test('POST /agent-applications returns 401 when unauthenticated', function () {
    $response = $this->postJson('/v1/agent-applications', ['agent_type' => 'individual']);

    $response->assertStatus(401);
});

test('POST /agent-applications with duplicate Idempotency-Key returns 200 replay', function () {
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.create');
    $idemKey  = 'idempotency-replay-' . \Ramsey\Uuid\Uuid::uuid7();
    $headers  = ['Idempotency-Key' => $idemKey];

    // First request — creates application
    $first = $this->withActorToken($user)
        ->withHeaders($headers)
        ->postJson('/v1/agent-applications', ['agent_type' => 'individual']);

    $first->assertStatus(201);
    $firstId = $first->json('data.id');

    // Second request — replay
    $second = $this->withActorToken($user)
        ->withHeaders($headers)
        ->postJson('/v1/agent-applications', ['agent_type' => 'individual']);

    $second->assertStatus(200);
    expect($second->json('data.id'))->toBe($firstId);

    // Only one application should exist for this user with this idempotency key
    $count = DB::connection('platform')->table('agent.agent_applications')
        ->where('user_id', $user->id)
        ->where('idempotency_key', $idemKey)
        ->count();
    expect($count)->toBe(1);
});

test('POST /agent-applications returns 422 VALIDATION_ERROR when agent_type is missing', function () {
    $user = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.create');

    $response = $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'test-missing-type-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson('/v1/agent-applications', []);

    $response->assertStatus(422);
});

test('POST /agent-applications emits agent.application.created outbox event', function () {
    $user = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.create');

    $response = $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'test-event-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson('/v1/agent-applications', ['agent_type' => 'individual']);

    $response->assertStatus(201);
    $appId = $response->json('data.id');

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.application.created')
        ->first();

    expect($outbox)->not->toBeNull();

    $payload = json_decode($outbox->payload, true);
    expect($payload['application_id'])->toBe($appId);
    expect($payload['user_id'])->toBe($user->id);
});
