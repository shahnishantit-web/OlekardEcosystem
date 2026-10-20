<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T068 ————————————————————————————————————————————————

test('POST /reject on kyc_passed application returns 200 with rejected status', function () {
    $user  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $admin = $this->makeActorWithPermissions('ADMIN', 'agent.reject');
    $app   = $this->createAgentApplication($user->id, status: ApplicationStatus::KycPassed);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'reject-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/reject", [
            'rejection_reason' => 'Documents are unclear and unverifiable.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', ApplicationStatus::Rejected->value);

    $row = DB::connection('platform')
        ->table('agent.agent_applications')
        ->where('id', $app->id)
        ->first();

    expect($row->status)->toBe(ApplicationStatus::Rejected->value);
    expect($row->rejection_reason)->toBe('Documents are unclear and unverifiable.');
});

test('POST /reject without reason returns 422 VALIDATION_ERROR', function () {
    $user  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $admin = $this->makeActorWithPermissions('ADMIN', 'agent.reject');
    $app   = $this->createAgentApplication($user->id, status: ApplicationStatus::KycPassed);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'reject-no-reason-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/reject", []);

    $response->assertStatus(422);
});

test('POST /reject on already approved application returns 422 APPLICATION_NOT_APPROVABLE', function () {
    $admin  = $this->makeActorWithPermissions('ADMIN', 'agent.reject');
    $user   = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $result = $this->createApprovedApplication($user->id, $admin->id);

    $response = $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'reject-terminal-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$result['application']->id}/reject", [
            'rejection_reason' => 'Trying to reject already approved.',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'APPLICATION_NOT_APPROVABLE');
});

test('POST /reject emits agent.rejected event in outbox', function () {
    $user  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $admin = $this->makeActorWithPermissions('ADMIN', 'agent.reject');
    $app   = $this->createAgentApplication($user->id, status: ApplicationStatus::KycPassed);

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => 'reject-event-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/reject", [
            'rejection_reason' => 'Fraudulent documents detected.',
        ])->assertStatus(200);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.rejected')
        ->first();

    expect($outbox)->not->toBeNull();
});

test('POST /reject with duplicate Idempotency-Key returns 200 no duplicate event', function () {
    $user    = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $admin   = $this->makeActorWithPermissions('ADMIN', 'agent.reject');
    $app     = $this->createAgentApplication($user->id, status: ApplicationStatus::KycPassed);
    $idemKey = 'reject-idem-' . \Ramsey\Uuid\Uuid::uuid7();

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agent-applications/{$app->id}/reject", ['rejection_reason' => 'First reject.'])
        ->assertStatus(200);

    $countBefore = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.rejected')
        ->count();

    $this->withActorToken($admin)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agent-applications/{$app->id}/reject", ['rejection_reason' => 'First reject.'])
        ->assertStatus(200);

    $countAfter = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.rejected')
        ->count();

    expect($countAfter)->toBe($countBefore);
});
