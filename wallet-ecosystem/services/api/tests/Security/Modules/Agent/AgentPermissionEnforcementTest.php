<?php

declare(strict_types=1);

use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T110 ————————————————————————————————————————————————

test('unauthenticated requests to all 14 agent routes return 401', function (string $method, string $url) {
    $response = $this->$method($url);
    $response->assertStatus(401);
})->with([
    ['postJson', '/v1/agent-applications'],
    ['patchJson', '/v1/agent-applications/00000000-0000-0000-0000-000000000001/personal-information'],
    ['patchJson', '/v1/agent-applications/00000000-0000-0000-0000-000000000001/business-information'],
    ['postJson', '/v1/agent-applications/00000000-0000-0000-0000-000000000001/documents'],
    ['postJson', '/v1/agent-applications/00000000-0000-0000-0000-000000000001/kyc'],
    ['postJson', '/v1/agent-applications/00000000-0000-0000-0000-000000000001/approve'],
    ['postJson', '/v1/agent-applications/00000000-0000-0000-0000-000000000001/reject'],
    ['postJson', '/v1/agents/00000000-0000-0000-0000-000000000001/suspend'],
    ['postJson', '/v1/agents/00000000-0000-0000-0000-000000000001/reactivate'],
    ['getJson', '/v1/agents/00000000-0000-0000-0000-000000000001/customers'],
    ['postJson', '/v1/agents/00000000-0000-0000-0000-000000000001/customers'],
    ['postJson', '/v1/agents/00000000-0000-0000-0000-000000000001/customers/00000000-0000-0000-0000-000000000002/kyc'],
    ['getJson', '/v1/agents/00000000-0000-0000-0000-000000000001/customers/00000000-0000-0000-0000-000000000002/wallet'],
    ['getJson', '/v1/agents/00000000-0000-0000-0000-000000000001/customers/00000000-0000-0000-0000-000000000002/transactions'],
]);

test('authenticated user without required permission gets 403 on agent application create', function () {
    $user = $this->makeActorWithPermissions('CUSTOMER');

    $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'perm-test-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson('/v1/agent-applications', ['agent_type' => 'individual'])
        ->assertStatus(403);
});

test('authenticated user without agent.approve permission gets 403 on approve endpoint', function () {
    $user = $this->makeActorWithPermissions('ADMIN', 'agent.application.read');

    $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'perm-approve-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson('/v1/agent-applications/00000000-0000-0000-0000-000000000001/approve')
        ->assertStatus(403);
});

test('authenticated user without agent.suspend permission gets 403 on suspend endpoint', function () {
    $user = $this->makeActorWithPermissions('ADMIN', 'agent.application.read');

    $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'perm-suspend-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson('/v1/agents/00000000-0000-0000-0000-000000000001/suspend', ['reason' => 'Unauthorized test.'])
        ->assertStatus(403);
});
