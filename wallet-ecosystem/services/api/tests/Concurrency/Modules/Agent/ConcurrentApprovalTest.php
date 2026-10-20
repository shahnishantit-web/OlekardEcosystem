<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T062 ————————————————————————————————————————————————

test('CC-E12-001: concurrent approval of same application creates exactly one Agent', function () {
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', 'agent.approval.require_maker_checker')
        ->update(['config_value' => 'false']);

    $user  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $admin = $this->makeActorWithPermissions('ADMIN', 'agent.approve');
    $app   = $this->createAgentApplication($user->id, status: ApplicationStatus::KycPassed);

    $idemKey1 = 'cc-approve-1-' . \Ramsey\Uuid\Uuid::uuid7();
    $idemKey2 = 'cc-approve-2-' . \Ramsey\Uuid\Uuid::uuid7();

    $responses = [];

    $r1 = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey1])
        ->postJson("/v1/agent-applications/{$app->id}/approve");

    $r2 = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey2])
        ->postJson("/v1/agent-applications/{$app->id}/approve");

    $responses[] = $r1->status();
    $responses[] = $r2->status();

    sort($responses);
    expect($responses[0])->toBe(200);
    expect($responses[1])->toBe(422);

    $agentCount = DB::connection('platform')
        ->table('agent.agents')
        ->where('application_id', $app->id)
        ->count();

    expect($agentCount)->toBe(1);
});
