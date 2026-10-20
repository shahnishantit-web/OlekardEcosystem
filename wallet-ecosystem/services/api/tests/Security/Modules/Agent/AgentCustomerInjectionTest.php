<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T109 — SC-E12-004: IDOR enforcement across all customer data endpoints ————

function makeAgentAndLinkedCustomer(AgentTestCase $case): array
{
    $admin     = $case->makeActorWithPermissions(
        'ADMIN',
        'agent.customer.search',
        'agent.customer.wallet.read',
        'agent.customer.transactions.read',
        'agent.customer.kyc.initiate',
    );
    $userA     = $case->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $resultA   = $case->createApprovedApplication($userA->id, $admin->id);
    $agentA    = $resultA['agent'];
    $customerA = $case->createLinkedCustomer($agentA->id);

    $userB     = $case->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $resultB   = $case->createApprovedApplication($userB->id, $admin->id);
    $agentB    = $resultB['agent'];
    $customerB = $case->createLinkedCustomer($agentB->id);

    return compact('agentA', 'customerA', 'customerB', 'admin');
}

test('SC-E12-004: search returns 200 but other agent customer data excluded (no data leak)', function () {
    $data      = makeAgentAndLinkedCustomer($this);
    $agentA    = $data['agentA'];
    $customerB = $data['customerB'];
    $admin     = $data['admin'];

    // Call with agentA's URL scope — customerB is NOT linked to agentA
    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agentA->id}/customers?search={$customerB->first_name}");

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($customerB->id);
});

test('SC-E12-004: wallet endpoint with other-agent customer_id returns 403 not 404', function () {
    $data      = makeAgentAndLinkedCustomer($this);
    $agentA    = $data['agentA'];
    $customerB = $data['customerB'];
    $admin     = $data['admin'];

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agentA->id}/customers/{$customerB->id}/wallet");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'UNAUTHORIZED_CUSTOMER_ACCESS');
    expect($response->status())->not->toBe(404);
});

test('SC-E12-004: transactions endpoint with other-agent customer_id returns 403 not 404', function () {
    $data      = makeAgentAndLinkedCustomer($this);
    $agentA    = $data['agentA'];
    $customerB = $data['customerB'];
    $admin     = $data['admin'];

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agentA->id}/customers/{$customerB->id}/transactions");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'UNAUTHORIZED_CUSTOMER_ACCESS');
    expect($response->status())->not->toBe(404);
});

test('SC-E12-004: kyc-initiate with other-agent customer_id returns 403 and emits unauthorized event', function () {
    $data      = makeAgentAndLinkedCustomer($this);
    $agentA    = $data['agentA'];
    $customerB = $data['customerB'];
    $admin     = $data['admin'];

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'idor-kyc-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agentA->id}/customers/{$customerB->id}/kyc");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'UNAUTHORIZED_CUSTOMER_ACCESS');

    $securityEvent = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'security.unauthorized_access_attempt')
        ->first();

    expect($securityEvent)->not->toBeNull();
});
