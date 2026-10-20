<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T037 ————————————————————————————————————————————————

$validPayload = static fn () => [
    'first_name'         => 'John',
    'last_name'          => 'Doe',
    'national_id_number' => '123456789',
    'national_id_type'   => 'national_id',
    'date_of_birth'      => '1985-06-15',
    'phone'              => '+26771234567',
    'address'            => [
        'street'  => '123 Main St',
        'city'    => 'Gaborone',
        'country' => 'BW',
    ],
];

test('PATCH personal-information on draft application returns 200 and persists data', function () use ($validPayload) {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);

    $response = $this->withActorToken($user)
        ->patchJson("/v1/agent-applications/{$application->id}/personal-information", $validPayload());

    $response->assertStatus(200)
        ->assertJsonPath('data.status', ApplicationStatus::InProgress->value);

    $row = DB::connection('platform')
        ->table('agent.agent_applications')
        ->where('id', $application->id)
        ->first();

    expect($row)->not->toBeNull();
    $personalInfo = json_decode($row->personal_info, true);
    expect($personalInfo['first_name'])->toBe('John');
    expect($personalInfo['last_name'])->toBe('Doe');
    expect($personalInfo['phone'])->toBe('+26771234567');
    expect($row->status)->toBe(ApplicationStatus::InProgress->value);
    expect($row->last_updated_by)->toBe($user->id);
});

test('PATCH personal-information by different owner returns 403', function () use ($validPayload) {
    $owner       = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $intruder    = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($owner->id);

    $response = $this->withActorToken($intruder)
        ->patchJson("/v1/agent-applications/{$application->id}/personal-information", $validPayload());

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'UNAUTHORIZED');
});

test('PATCH personal-information on submitted application returns 422 APPLICATION_NOT_EDITABLE', function () use ($validPayload) {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id, status: ApplicationStatus::Submitted);

    $response = $this->withActorToken($user)
        ->patchJson("/v1/agent-applications/{$application->id}/personal-information", $validPayload());

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'APPLICATION_NOT_EDITABLE');
});

test('PATCH personal-information with invalid national_id_type returns 422 VALIDATION_ERROR', function () use ($validPayload) {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);

    $payload = array_merge($validPayload(), ['national_id_type' => 'invalid_type']);

    $response = $this->withActorToken($user)
        ->patchJson("/v1/agent-applications/{$application->id}/personal-information", $payload);

    $response->assertStatus(422);
});

test('PATCH personal-information emits agent.application.personal_info_updated outbox event', function () use ($validPayload) {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);

    $this->withActorToken($user)
        ->patchJson("/v1/agent-applications/{$application->id}/personal-information", $validPayload());

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.application.personal_info_updated')
        ->first();

    expect($outbox)->not->toBeNull();

    $payload = json_decode($outbox->payload, true);
    expect($payload['application_id'])->toBe($application->id);
    expect($payload['actor_id'])->toBe($user->id);
});
