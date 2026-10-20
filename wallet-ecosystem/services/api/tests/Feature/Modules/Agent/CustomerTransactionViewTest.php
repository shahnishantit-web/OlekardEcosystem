<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T103 ————————————————————————————————————————————————

function insertAgentTransaction(string $customerId, string $agentId): void
{
    DB::connection('platform')->table('transaction.transaction_projections')->insert([
        'id'            => Uuid::uuid7()->toString(),
        'customer_id'   => $customerId,
        'wallet_id'     => Uuid::uuid7()->toString(),
        'type'          => 'funding',
        'status'        => 'completed',
        'amount'        => 10000,
        'currency_code' => 'BWP',
        'direction'     => 'credit',
        'reference'     => 'REF-' . str_replace('-', '', Uuid::uuid7()->toString()),
        'source_module' => 'funding',
        'metadata'      => json_encode(['source_agent_id' => $agentId]),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
}

function insertNonAgentTransaction(string $customerId): void
{
    DB::connection('platform')->table('transaction.transaction_projections')->insert([
        'id'            => Uuid::uuid7()->toString(),
        'customer_id'   => $customerId,
        'wallet_id'     => Uuid::uuid7()->toString(),
        'type'          => 'transfer',
        'status'        => 'completed',
        'amount'        => 5000,
        'currency_code' => 'BWP',
        'direction'     => 'debit',
        'reference'     => 'REF-' . str_replace('-', '', Uuid::uuid7()->toString()),
        'source_module' => 'transfer',
        'metadata'      => json_encode([]),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
}

test('AC-1: linked customer returns 200 with only agent-channel transactions', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.transactions.read');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];
    $customer = $this->createLinkedCustomer($agent->id);

    insertAgentTransaction($customer->id, $agent->id);
    insertAgentTransaction($customer->id, $agent->id);
    insertNonAgentTransaction($customer->id);

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers/{$customer->id}/transactions");

    $response->assertStatus(200);

    $data = $response->json('data');
    expect(count($data))->toBe(2);

    foreach ($data as $tx) {
        expect($tx)->toHaveKey('id');
        expect($tx)->toHaveKey('type');
        expect($tx)->toHaveKey('status');
        expect($tx)->toHaveKey('amount');
        expect($tx)->not->toHaveKey('metadata');
    }
});

test('AC-2: unlinked customer returns 403 UNAUTHORIZED_CUSTOMER_ACCESS', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.transactions.read');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $otherUser = $this->makeActorWithPermissions('CUSTOMER');

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers/{$otherUser->id}/transactions");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'UNAUTHORIZED_CUSTOMER_ACCESS');
});

test('AC-3: transaction view emits agent.customer.transactions_viewed in outbox', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.transactions.read');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];
    $customer = $this->createLinkedCustomer($agent->id);

    $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers/{$customer->id}/transactions")
        ->assertStatus(200);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.customer.transactions_viewed')
        ->first();

    expect($outbox)->not->toBeNull();
    $payload = json_decode($outbox->payload, true);
    expect($payload['agent_id'])->toBe($agent->id);
    expect($payload['customer_id'])->toBe($customer->id);
});
