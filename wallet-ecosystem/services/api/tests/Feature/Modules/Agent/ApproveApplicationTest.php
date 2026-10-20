<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T061 ————————————————————————————————————————————————

function makeKycPassedApplication(object $user, AgentTestCase $case): object
{
    return $case->createAgentApplication($user->id, status: ApplicationStatus::KycPassed);
}

test('POST /approve on kyc_passed application returns 200 with active Agent', function () {
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', 'agent.approval.require_maker_checker')
        ->update(['config_value' => 'false']);

    $user  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $admin = $this->makeActorWithPermissions('ADMIN', 'agent.approve');
    $app   = makeKycPassedApplication($user, $this);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'approve-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/approve");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', AgentStatus::Active->value);

    $agent = DB::connection('platform')
        ->table('agent.agents')
        ->where('application_id', $app->id)
        ->first();

    expect($agent)->not->toBeNull();
    expect($agent->status)->toBe(AgentStatus::Active->value);
});

test('POST /approve with maker-checker violation returns 422 MAKER_CHECKER_VIOLATION', function () {
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', 'agent.approval.require_maker_checker')
        ->update(['config_value' => 'true']);

    $user  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $admin = $this->makeActorWithPermissions('ADMIN', 'agent.approve');
    $app   = makeKycPassedApplication($user, $this);

    // Set last_updated_by to admin (simulating admin touched the application)
    DB::connection('platform')
        ->table('agent.agent_applications')
        ->where('id', $app->id)
        ->update(['last_updated_by' => $admin->id]);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'approve-mk-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/approve");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'MAKER_CHECKER_VIOLATION');
});

test('POST /approve on wrong status returns 422 APPLICATION_NOT_APPROVABLE', function () {
    $admin = $this->makeActorWithPermissions('ADMIN', 'agent.approve');
    $user  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $app   = $this->createAgentApplication($user->id, status: ApplicationStatus::Draft);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'approve-wrong-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/approve");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'APPLICATION_NOT_APPROVABLE');
});

test('POST /approve with duplicate Idempotency-Key returns 200 with existing Agent', function () {
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', 'agent.approval.require_maker_checker')
        ->update(['config_value' => 'false']);

    $user    = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $admin   = $this->makeActorWithPermissions('ADMIN', 'agent.approve');
    $app     = makeKycPassedApplication($user, $this);
    $idemKey = 'approve-idem-' . \Ramsey\Uuid\Uuid::uuid7();

    $first = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agent-applications/{$app->id}/approve");

    $first->assertStatus(200);
    $agentId = $first->json('data.id');

    $second = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agent-applications/{$app->id}/approve");

    $second->assertStatus(200);
    expect($second->json('data.id'))->toBe($agentId);

    $count = DB::connection('platform')
        ->table('agent.agents')
        ->where('application_id', $app->id)
        ->count();
    expect($count)->toBe(1);
});

test('POST /approve emits agent.approved event in outbox', function () {
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', 'agent.approval.require_maker_checker')
        ->update(['config_value' => 'false']);

    $user  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $admin = $this->makeActorWithPermissions('ADMIN', 'agent.approve');
    $app   = makeKycPassedApplication($user, $this);

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'approve-event-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/approve")
        ->assertStatus(200);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.approved')
        ->first();

    expect($outbox)->not->toBeNull();
    $payload = json_decode($outbox->payload, true);
    expect($payload['application_id'])->toBe($app->id);
    expect($payload['user_id'])->toBe($user->id);
});
