<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T093 ————————————————————————————————————————————————

test('AC-1: linked pending_kyc customer returns 202 and event emitted', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.kyc.initiate');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];
    $customer = $this->createLinkedCustomer($agent->id, status: 'pending_kyc');

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'kyc-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/customers/{$customer->id}/kyc");

    $response->assertStatus(202);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.customer.kyc_initiated')
        ->first();

    expect($outbox)->not->toBeNull();
});

test('AC-2: unlinked customer returns 403 UNAUTHORIZED_CUSTOMER_ACCESS not 404', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.kyc.initiate');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $otherUser = $this->makeActorWithPermissions('CUSTOMER');

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'kyc-idor-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/customers/{$otherUser->id}/kyc");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'UNAUTHORIZED_CUSTOMER_ACCESS');
});

test('AC-3: already kyc_verified customer returns 422 KYC_ALREADY_COMPLETED', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.kyc.initiate');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];
    $customer = $this->createLinkedCustomer($agent->id, status: 'kyc_verified');

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'kyc-done-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/customers/{$customer->id}/kyc");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'KYC_ALREADY_COMPLETED');
});

test('AC-4: agent.customer.kyc_initiated event emitted in outbox', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.kyc.initiate');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];
    $customer = $this->createLinkedCustomer($agent->id, status: 'pending_kyc');

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'kyc-evt-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agent->id}/customers/{$customer->id}/kyc")
        ->assertStatus(202);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.customer.kyc_initiated')
        ->first();

    expect($outbox)->not->toBeNull();
    $payload = json_decode($outbox->payload, true);
    expect($payload['agent_id'])->toBe($agent->id);
    expect($payload['customer_id'])->toBe($customer->id);
});
