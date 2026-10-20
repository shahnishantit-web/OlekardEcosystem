<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T120 — SC-E12-002: Admin approve round-trip latency ————————————————————————

test('SC-E12-002: POST /approve round-trip completes in under 120 seconds', function () {
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', 'agent.approval.require_maker_checker')
        ->update(['config_value' => 'false']);

    $admin = $this->makeActorWithPermissions('ADMIN', 'agent.approve');
    $user  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');

    $appId = (string) \Ramsey\Uuid\Uuid::uuid7();
    DB::connection('platform')->table('agent.agent_applications')->insert([
        'id'             => $appId,
        'user_id'        => $user->id,
        'agent_type'     => 'individual',
        'status'         => ApplicationStatus::KycPassed->value,
        'correlation_id' => (string) \Ramsey\Uuid\Uuid::uuid7(),
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    $start = microtime(true);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'latency-approve-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$appId}/approve");

    $elapsedMs = (microtime(true) - $start) * 1000;

    $response->assertStatus(200);
    expect($elapsedMs)->toBeLessThan(120_000, "Approve took {$elapsedMs}ms, expected < 120000ms");
});
