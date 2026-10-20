<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\AgentStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T088 ————————————————————————————————————————————————

test('AC-1: valid registration returns 201 and relationship is created', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.register');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $phone = '+26771' . mt_rand(100000, 999999);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'register-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/customers", [
            'phone'      => $phone,
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'id_type'    => 'national_id',
            'id_number'  => 'BW12345678',
        ]);

    $response->assertStatus(201);

    $customerId = $response->json('data.id');

    $relationship = DB::connection('platform')
        ->table('agent.agent_customer_relationships')
        ->where('agent_id', $agent->id)
        ->where('customer_id', $customerId)
        ->first();

    expect($relationship)->not->toBeNull();
    expect($relationship->mobile_number)->toBe($phone);
    expect($relationship->first_name)->toBe('John');
});

test('AC-2: duplicate phone returns 422 PHONE_ALREADY_REGISTERED', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.register');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $phone = '+267719' . mt_rand(10000, 99999);

    $this->createLinkedCustomer($agent->id, phone: $phone);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'register-dup-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/customers", [
            'phone'      => $phone,
            'first_name' => 'Jane',
            'last_name'  => 'Doe',
            'id_type'    => 'national_id',
            'id_number'  => 'BW99999999',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'PHONE_ALREADY_REGISTERED');
});

test('AC-3: suspended agent register returns 403 AGENT_SUSPENDED', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.register');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    DB::connection('platform')
        ->table('agent.agents')
        ->where('id', $agent->id)
        ->update(['status' => AgentStatus::Suspended->value, 'suspended_at' => now()]);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'register-susp-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/customers", [
            'phone'      => '+26771200000',
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'id_type'    => 'national_id',
            'id_number'  => 'BW12345678',
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'AGENT_SUSPENDED');
});

test('AC-4: register emits agent.customer.registered in outbox', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.register');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $phone = '+26771' . mt_rand(100000, 999999);

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'register-evt-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/customers", [
            'phone'      => $phone,
            'first_name' => 'Event',
            'last_name'  => 'Test',
            'id_type'    => 'national_id',
            'id_number'  => 'BW00000001',
        ])
        ->assertStatus(201);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.customer.registered')
        ->first();

    expect($outbox)->not->toBeNull();
    $payload = json_decode($outbox->payload, true);
    expect($payload['agent_id'])->toBe($agent->id);
});

test('AC-5: duplicate Idempotency-Key returns 200 replay with no duplicate relationship', function () {
    $admin   = $this->makeActorWithPermissions('ADMIN', 'agent.customer.register');
    $user    = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result  = $this->createApprovedApplication($user->id, $admin->id);
    $agent   = $result['agent'];
    $idemKey = 'register-idem-' . \Ramsey\Uuid\Uuid::uuid7();
    $phone   = '+26771' . mt_rand(100000, 999999);

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agents/{$agent->id}/customers", [
            'phone'      => $phone,
            'first_name' => 'Idempotent',
            'last_name'  => 'Test',
            'id_type'    => 'national_id',
            'id_number'  => 'BW11111111',
        ])
        ->assertStatus(201);

    $countBefore = DB::connection('platform')
        ->table('agent.agent_customer_relationships')
        ->where('agent_id', $agent->id)
        ->count();

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agents/{$agent->id}/customers", [
            'phone'      => $phone,
            'first_name' => 'Idempotent',
            'last_name'  => 'Test',
            'id_type'    => 'national_id',
            'id_number'  => 'BW11111111',
        ])
        ->assertStatus(200);

    $countAfter = DB::connection('platform')
        ->table('agent.agent_customer_relationships')
        ->where('agent_id', $agent->id)
        ->count();

    expect($countAfter)->toBe($countBefore);
});
