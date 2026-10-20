<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T119 — SC-E12-003: Customer search performance under 10k linked customers —

test('SC-E12-003: search responds in under 2000ms with 10k linked customers', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.customer.search');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);
    $agent  = $result['agent'];

    // Seed 10,000 customer records
    $batchSize = 500;
    $total     = 10_000;
    $batches   = $total / $batchSize;

    for ($b = 0; $b < $batches; $b++) {
        $identityRows      = [];
        $relationshipRows  = [];

        for ($i = 0; $i < $batchSize; $i++) {
            $customerId = Uuid::uuid7()->toString();
            $mobile     = '+267' . sprintf('%09d', ($b * $batchSize) + $i + 100000000);

            $identityRows[] = [
                'id'            => $customerId,
                'mobile_number' => $mobile,
                'status'        => 'pending_kyc',
                'actor_type'    => 'CUSTOMER',
                'created_at'    => now(),
                'updated_at'    => now(),
            ];

            $relationshipRows[] = [
                'id'                 => Uuid::uuid7()->toString(),
                'agent_id'           => $agent->id,
                'customer_id'        => $customerId,
                'registered_by'      => $agent->user_id,
                'registered_at'      => now(),
                'first_name'         => 'Customer' . (($b * $batchSize) + $i),
                'last_name'          => 'Test',
                'mobile_number'      => $mobile,
                'national_id_type'   => 'national_id',
                'national_id_number' => 'BW' . sprintf('%09d', ($b * $batchSize) + $i),
                'customer_status'    => 'pending_kyc',
            ];
        }

        DB::connection('identity')->table('identity.users')->insert($identityRows);
        DB::connection('platform')->table('agent.agent_customer_relationships')->insert($relationshipRows);
    }

    // Measure search performance
    $start = microtime(true);

    $response = $this->withActorToken($admin)
        ->getJson("/v1/agents/{$agent->id}/customers?search=Customer5");

    $elapsedMs = (microtime(true) - $start) * 1000;

    $response->assertStatus(200);
    expect($elapsedMs)->toBeLessThan(2000, "Search took {$elapsedMs}ms, expected < 2000ms");
})->group('performance');
