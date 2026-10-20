<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\AgentStatus;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T098 ————————————————————————————————————————————————

function createWalletForCustomer(string $customerId, bool $isFrozen = false): string
{
    $walletId = Uuid::uuid7()->toString();
    $status   = $isFrozen ? 'frozen' : 'active';

    DB::connection('platform')->table('wallet.wallets')->insert([
        'id'               => $walletId,
        'wallet_reference' => 'WLT-' . substr($walletId, 0, 8),
        'owner_id'         => $customerId,
        'status'           => $status,
        'created_by'       => $customerId,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    return $walletId;
}

test('AC-1: linked customer wallet returns 200 with wallet fields', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.wallet.read');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];
    $customer = $this->createLinkedCustomer($agent->id);
    $walletId = createWalletForCustomer($customer->id);

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers/{$customer->id}/wallet");

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data['wallet_id'])->toBe($walletId);
    expect($data['status'])->toBe('active');
    expect($data['is_frozen'])->toBeFalse();
    expect($data['balance_disclosure'])->toBeNull();
});

test('AC-2: unlinked customer wallet returns 403 UNAUTHORIZED_CUSTOMER_ACCESS', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.wallet.read');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    $otherUser = $this->makeActorWithPermissions('CUSTOMER');

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers/{$otherUser->id}/wallet");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'UNAUTHORIZED_CUSTOMER_ACCESS');
});

test('AC-3: suspended agent wallet view returns 403 AGENT_SUSPENDED', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.wallet.read');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];
    $customer = $this->createLinkedCustomer($agent->id);

    DB::connection('platform')
        ->table('agent.agents')
        ->where('id', $agent->id)
        ->update(['status' => AgentStatus::Suspended->value, 'suspended_at' => now()]);

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers/{$customer->id}/wallet");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'AGENT_SUSPENDED');
});

test('AC-4: frozen customer wallet returns is_frozen=true', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.wallet.read');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];
    $customer = $this->createLinkedCustomer($agent->id);
    createWalletForCustomer($customer->id, isFrozen: true);

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers/{$customer->id}/wallet");

    $response->assertStatus(200)
        ->assertJsonPath('data.is_frozen', true);
});

test('AC-5: wallet view emits agent.customer.wallet_viewed event in outbox', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.wallet.read');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];
    $customer = $this->createLinkedCustomer($agent->id);
    createWalletForCustomer($customer->id);

    $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers/{$customer->id}/wallet")
        ->assertStatus(200);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.customer.wallet_viewed')
        ->first();

    expect($outbox)->not->toBeNull();
    $payload = json_decode($outbox->payload, true);
    expect($payload['agent_id'])->toBe($agent->id);
    expect($payload['customer_id'])->toBe($customer->id);
});
