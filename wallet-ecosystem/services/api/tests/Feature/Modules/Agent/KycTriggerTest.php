<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use App\Modules\Agent\Domain\Enums\DocumentStatus;
use App\Modules\Agent\Domain\Enums\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T054 ————————————————————————————————————————————————

function makeCompleteApplication(object $user, AgentTestCase $case): object
{
    $personalInfo = [
        'first_name'         => 'John',
        'last_name'          => 'Doe',
        'national_id_number' => '123456789',
        'national_id_type'   => 'national_id',
        'date_of_birth'      => '1985-06-15',
        'phone'              => '+26771234567',
        'address'            => ['street' => '1 Main St', 'city' => 'Gaborone', 'country' => 'BW'],
    ];

    $app = $case->createAgentApplication($user->id, status: ApplicationStatus::InProgress);
    DB::connection('platform')
        ->table('agent.agent_applications')
        ->where('id', $app->id)
        ->update(['personal_info' => json_encode($personalInfo)]);

    // Upload a national ID document
    $docId = (string)\Ramsey\Uuid\Uuid::uuid7();
    DB::connection('platform')->table('agent.agent_documents')->insert([
        'id'                => $docId,
        'application_id'    => $app->id,
        'document_type'     => DocumentType::NationalId->value,
        'storage_reference' => 'stub/path/id.pdf',
        'mime_type'         => 'application/pdf',
        'size_bytes'        => 100,
        'status'            => DocumentStatus::PendingReview->value,
        'uploaded_at'       => now(),
    ]);

    return $app->fresh();
}

test('POST /kyc on complete application returns 202 with kyc_in_progress status', function () {
    $user = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $app  = makeCompleteApplication($user, $this);

    $response = $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'kyc-test-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/kyc");

    $response->assertStatus(202)
        ->assertJsonPath('data.status', ApplicationStatus::KycInProgress->value);
});

test('POST /kyc on application missing personal_info returns 422 APPLICATION_INCOMPLETE', function () {
    $user = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $app  = $this->createAgentApplication($user->id, status: ApplicationStatus::InProgress);

    $response = $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'kyc-incomplete-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/kyc");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'APPLICATION_INCOMPLETE');
});

test('stub KYC provider auto-transitions to kyc_passed when configured as passed', function () {
    DB::connection('platform')->statement(
        "INSERT INTO platform.configuration_values (id, config_key, config_value, value_type, status, version, is_tbd, created_at, updated_at)
         VALUES (gen_random_uuid(), 'agent.kyc.stub_result', 'passed', 'string', 'active', 1, false, NOW(), NOW())
         ON CONFLICT (config_key, version) DO UPDATE SET config_value = 'passed', updated_at = NOW()"
    );

    $user = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $app  = makeCompleteApplication($user, $this);

    $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'kyc-pass-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/kyc")
        ->assertStatus(202);

    $row = DB::connection('platform')
        ->table('agent.agent_applications')
        ->where('id', $app->id)
        ->first();

    expect($row->status)->toBe(ApplicationStatus::KycPassed->value);
    expect($row->kyc_result)->toBe('passed');
});

test('stub KYC provider auto-transitions to kyc_failed when configured as failed', function () {
    DB::connection('platform')->statement(
        "INSERT INTO platform.configuration_values (id, config_key, config_value, value_type, status, version, is_tbd, created_at, updated_at)
         VALUES (gen_random_uuid(), 'agent.kyc.stub_result', 'failed', 'string', 'active', 1, false, NOW(), NOW())
         ON CONFLICT (config_key, version) DO UPDATE SET config_value = 'failed', updated_at = NOW()"
    );

    $user = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $app  = makeCompleteApplication($user, $this);

    $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'kyc-fail-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/kyc")
        ->assertStatus(202);

    $row = DB::connection('platform')
        ->table('agent.agent_applications')
        ->where('id', $app->id)
        ->first();

    expect($row->status)->toBe(ApplicationStatus::KycFailed->value);
    expect($row->kyc_result)->toBe('failed');
    expect($row->kyc_failure_reason)->toBe('STUB_KYC_FAILURE');
});

test('admin KYC bypass transitions application to kyc_passed and emits bypass event', function () {
    DB::connection('platform')->statement(
        "INSERT INTO platform.configuration_values (id, config_key, config_value, value_type, status, version, is_tbd, created_at, updated_at)
         VALUES (gen_random_uuid(), 'agent.kyc.admin_bypass', 'true', 'boolean', 'active', 1, false, NOW(), NOW())
         ON CONFLICT (config_key, version) DO UPDATE SET config_value = 'true', updated_at = NOW()"
    );

    $user = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $app  = makeCompleteApplication($user, $this);

    $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => 'kyc-bypass-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$app->id}/kyc")
        ->assertStatus(202);

    // Cleanup is handled by DatabaseTransactions rollback

    $row = DB::connection('platform')
        ->table('agent.agent_applications')
        ->where('id', $app->id)
        ->first();

    expect($row->status)->toBe(ApplicationStatus::KycPassed->value);
    expect($row->kyc_provider_reference)->toBe('ADMIN_KYC_BYPASS');

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.application.kyc_bypassed')
        ->first();

    expect($outbox)->not->toBeNull();
});

test('duplicate Idempotency-Key returns 202 with current status without re-emitting kyc_initiated event', function () {
    $user     = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $app      = makeCompleteApplication($user, $this);
    $idemKey  = 'kyc-idem-' . \Ramsey\Uuid\Uuid::uuid7();

    $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agent-applications/{$app->id}/kyc")
        ->assertStatus(202);

    $countBefore = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.application.kyc_initiated')
        ->count();

    $this->withActorToken($user)
        ->withHeaders(['Idempotency-Key' => $idemKey])
        ->postJson("/v1/agent-applications/{$app->id}/kyc")
        ->assertStatus(202);

    $countAfter = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.application.kyc_initiated')
        ->count();

    expect($countAfter)->toBe($countBefore);
});
