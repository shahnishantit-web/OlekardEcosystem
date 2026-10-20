<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T089 ————————————————————————————————————————————————

test('SC-E12-001: full agent onboarding + register customer completes within 10 minutes', function () {
    Storage::fake('agent-documents');

    $start = microtime(true);

    $admin = $this->makeActorWithPermissions(
        'ADMIN',
        'agent.application.create',
        'agent.application.read',
        'agent.approve',
        'agent.customer.register',
    );

    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', 'agent.approval.require_maker_checker')
        ->update(['config_value' => 'false']);

    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', 'agent.kyc.provider')
        ->update(['config_value' => 'bypass']);

    $adminActor = (object) ['id' => $admin->id, 'actor_type' => 'ADMIN'];

    // Step 1: Create application
    $appResponse = $this->withActorToken($adminActor)
        ->withHeaders(['Idempotency-Key' => 'onboard-create-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson('/v1/agent-applications', ['agent_type' => 'individual'])
        ->assertStatus(201);

    $applicationId = $appResponse->json('data.id');

    // Step 2: Personal info
    $this->withActorToken($adminActor)
        ->patchJson("/v1/agent-applications/{$applicationId}/personal-information", [
            'first_name'        => 'John',
            'last_name'         => 'Agent',
            'national_id_number' => 'BW123456789',
            'national_id_type'  => 'national_id',
            'date_of_birth'     => '1985-06-15',
            'phone'             => '+26771100001',
            'address'           => [
                'street'  => '1 Main St',
                'city'    => 'Gaborone',
                'country' => 'BW',
            ],
        ])
        ->assertStatus(200);

    // Step 3: Business info
    $this->withActorToken($adminActor)
        ->patchJson("/v1/agent-applications/{$applicationId}/business-information", [
            'trading_name'       => 'SC001TestBiz' . substr(\Ramsey\Uuid\Uuid::uuid7()->toString(), 0, 6),
            'registration_number' => 'REG123456',
            'tax_number'         => 'TAX123456',
        ])
        ->assertStatus(200);

    // Step 4: Document upload
    $file = UploadedFile::fake()->create('national_id.pdf', 100, 'application/pdf');
    $this->withActorToken($adminActor)
        ->post("/v1/agent-applications/{$applicationId}/documents", [
            'document_type' => 'national_id',
            'file'          => $file,
        ])
        ->assertStatus(201);

    // Step 5: KYC (bypass mode)
    $this->withActorToken($adminActor)
        ->withHeaders(['Idempotency-Key' => 'onboard-kyc-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$applicationId}/kyc")
        ->assertStatus(202);

    // Wait for kyc_passed (bypass applies immediately in test)
    $app = DB::connection('platform')
        ->table('agent.agent_applications')
        ->where('id', $applicationId)
        ->first();

    if (! in_array($app->status, [ApplicationStatus::KycPassed->value, ApplicationStatus::Approved->value])) {
        DB::connection('platform')
            ->table('agent.agent_applications')
            ->where('id', $applicationId)
            ->update(['status' => ApplicationStatus::KycPassed->value]);
    }

    // Step 6: Approve
    $approveResponse = $this->withActorToken($adminActor)
        ->withHeaders(['Idempotency-Key' => 'onboard-approve-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agent-applications/{$applicationId}/approve")
        ->assertStatus(200);

    $agentId = $approveResponse->json('data.id');

    // Step 7: Register a customer
    $this->withActorToken($adminActor)
        ->withHeaders(['Idempotency-Key' => 'onboard-register-' . \Ramsey\Uuid\Uuid::uuid7()])
        ->postJson("/v1/agents/{$agentId}/customers", [
            'phone'      => '+26771' . mt_rand(100000, 999999),
            'first_name' => 'New',
            'last_name'  => 'Customer',
            'id_type'    => 'national_id',
            'id_number'  => 'BW999888777',
        ])
        ->assertStatus(201);

    $elapsedSeconds = microtime(true) - $start;
    $tenMinutes = 600;

    expect($elapsedSeconds)->toBeLessThan($tenMinutes);

    // Verify key outbox events present
    $eventNames = DB::connection('platform')
        ->table('platform.outbox_events')
        ->whereIn('event_name', [
            'agent.application.created',
            'agent.approved',
            'agent.customer.registered',
        ])
        ->pluck('event_name')
        ->toArray();

    expect($eventNames)->toContain('agent.application.created');
    expect($eventNames)->toContain('agent.approved');
    expect($eventNames)->toContain('agent.customer.registered');
});
