<?php

declare(strict_types=1);

use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T111 — FR-E12-022: PII storage and response masking ————————————————————————

test('CustomerSummaryResource always masks national_id to last 4 digits', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.search');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];

    $this->createLinkedCustomer($agent->id, firstName: 'Alice');

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers?search=Alice");

    $response->assertStatus(200);

    $items = $response->json('data');
    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        expect($item)->not->toHaveKey('national_id_number');
        if (isset($item['masked_national_id']) && $item['masked_national_id'] !== null) {
            expect($item['masked_national_id'])->toStartWith('****');
            expect(strlen($item['masked_national_id']))->toBe(8);
        }
    }
});

test('CustomerWalletViewResource never exposes raw balance amount', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.wallet.read');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];
    $customer = $this->createLinkedCustomer($agent->id);

    \Illuminate\Support\Facades\DB::connection('platform')->table('wallet.wallets')->insert([
        'id'            => \Ramsey\Uuid\Uuid::uuid7()->toString(),
        'user_id'       => $customer->id,
        'status'        => 'active',
        'tier'          => 'tier_1',
        'currency_code' => 'BWP',
        'is_frozen'     => false,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers/{$customer->id}/wallet");

    $response->assertStatus(200);

    $data = $response->json('data');
    expect($data)->toHaveKey('balance_disclosure');
    expect($data['balance_disclosure'])->toBeNull();
    expect($data)->not->toHaveKey('balance');
    expect($data)->not->toHaveKey('available_balance');
    expect($data)->not->toHaveKey('ledger_balance');
});

test('search response does not expose PIN or password_hash', function () {
    $admin    = $this->makeActorWithPermissions('ADMIN', 'agent.customer.search');
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result   = $this->createApprovedApplication($user->id, $admin->id);
    $agent    = $result['agent'];

    $this->createLinkedCustomer($agent->id, firstName: 'PiiTest');

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers?search=PiiTest");

    $response->assertStatus(200);
    $responseBody = $response->getContent();

    expect($responseBody)->not->toContain('password');
    expect($responseBody)->not->toContain('pin');
    expect($responseBody)->not->toContain('password_hash');
    expect($responseBody)->not->toContain('pin_hash');
});
