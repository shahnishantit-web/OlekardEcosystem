<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\AgentStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T083 ————————————————————————————————————————————————

test('AC-1: GET /customers with valid search returns 200 and CustomerSummary list', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.search');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $this->createLinkedCustomer($agent->id, firstName: 'Alice', lastName: 'Smith');
    $this->createLinkedCustomer($agent->id, firstName: 'Alice', lastName: 'Johnson');

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers?search=Alice");

    $response->assertStatus(200);

    $data = $response->json('data');
    expect(count($data))->toBeGreaterThanOrEqual(2);

    $firstItem = $data[0];
    expect($firstItem)->toHaveKey('id');
    expect($firstItem)->toHaveKey('first_name');
    expect($firstItem)->toHaveKey('last_name');
    expect($firstItem)->toHaveKey('masked_national_id');
    expect($firstItem)->toHaveKey('phone');
    expect($firstItem)->toHaveKey('status');
    expect($firstItem)->not->toHaveKey('national_id_number');
});

test('AC-2: suspended agent search returns 403 AGENT_SUSPENDED', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.search');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    DB::connection('platform')
        ->table('agent.agents')
        ->where('id', $agent->id)
        ->update(['status' => AgentStatus::Suspended->value, 'suspended_at' => now()]);

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers?search=Alice");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'AGENT_SUSPENDED');
});

test('AC-3: search query shorter than min_length returns 422 SEARCH_TOO_SHORT', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.search');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers?search=AB");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'SEARCH_TOO_SHORT');
});

test('AC-4: search with no matching customers returns 200 with empty data array', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.search');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers?search=Xyzzy");

    $response->assertStatus(200);
    expect($response->json('data'))->toBeArray()->toHaveCount(0);
});

test('AC-5: search emits agent.customer.searched event in outbox', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.search');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers?search=Xyzzy")
        ->assertStatus(200);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.customer.searched')
        ->first();

    expect($outbox)->not->toBeNull();
    $payload = json_decode($outbox->payload, true);
    expect($payload['agent_id'])->toBe($agent->id);
    expect($payload['query'])->toBe('Xyzzy');
});
