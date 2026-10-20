<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\DocumentStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T049 ————————————————————————————————————————————————

test('POST documents with valid PDF returns 201 and pending_review status', function () {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);

    $file = UploadedFile::fake()->create('id.pdf', 100, 'application/pdf');

    $response = $this->withActorToken($user)
        ->post("/v1/agent-applications/{$application->id}/documents", [
            'document_type' => 'national_id',
            'file'          => $file,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', DocumentStatus::PendingReview->value)
        ->assertJsonPath('data.document_type', 'national_id');

    $doc = DB::connection('platform')
        ->table('agent.agent_documents')
        ->where('application_id', $application->id)
        ->where('status', DocumentStatus::PendingReview->value)
        ->first();

    expect($doc)->not->toBeNull();
    expect($doc->storage_reference)->not->toBeNull();
});

test('POST documents with unsupported format returns 422 INVALID_DOCUMENT_FORMAT', function () {
    // The NullVirusScanner default is clean, so format check fires first
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);

    $file = UploadedFile::fake()->create('document.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $response = $this->withActorToken($user)
        ->post("/v1/agent-applications/{$application->id}/documents", [
            'document_type' => 'national_id',
            'file'          => $file,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_DOCUMENT_FORMAT');
});

test('POST documents over size limit returns 422 DOCUMENT_TOO_LARGE', function () {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);

    // Fake a file that reports a size larger than 10MB
    $file = UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf');

    $response = $this->withActorToken($user)
        ->post("/v1/agent-applications/{$application->id}/documents", [
            'document_type' => 'national_id',
            'file'          => $file,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'DOCUMENT_TOO_LARGE');
});

test('POST documents with same type twice supersedes first and creates new active document', function () {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);

    $file1 = UploadedFile::fake()->create('id1.pdf', 100, 'application/pdf');
    $file2 = UploadedFile::fake()->create('id2.pdf', 100, 'application/pdf');

    $this->withActorToken($user)
        ->post("/v1/agent-applications/{$application->id}/documents", [
            'document_type' => 'national_id',
            'file'          => $file1,
        ])->assertStatus(201);

    $this->withActorToken($user)
        ->post("/v1/agent-applications/{$application->id}/documents", [
            'document_type' => 'national_id',
            'file'          => $file2,
        ])->assertStatus(201);

    $docs = DB::connection('platform')
        ->table('agent.agent_documents')
        ->where('application_id', $application->id)
        ->where('document_type', 'national_id')
        ->orderBy('id')
        ->get();

    expect($docs)->toHaveCount(2);
    expect($docs[0]->status)->toBe(DocumentStatus::Rejected->value);
    expect($docs[0]->rejection_reason)->toBe('SUPERSEDED');
    expect($docs[1]->status)->toBe(DocumentStatus::PendingReview->value);
});

test('POST documents emits agent.application.document_uploaded outbox event', function () {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);

    $file = UploadedFile::fake()->create('id.pdf', 100, 'application/pdf');

    $this->withActorToken($user)
        ->post("/v1/agent-applications/{$application->id}/documents", [
            'document_type' => 'national_id',
            'file'          => $file,
        ])->assertStatus(201);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.application.document_uploaded')
        ->first();

    expect($outbox)->not->toBeNull();
    $payload = json_decode($outbox->payload, true);
    expect($payload['application_id'])->toBe($application->id);
    expect($payload['document_type'])->toBe('national_id');
});

test('POST documents with virus stub infected returns 422 DOCUMENT_VIRUS_DETECTED with quarantined record', function () {
    putenv('AGENT_VIRUS_SCAN_STUB_RESULT=infected');

    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);

    $file = UploadedFile::fake()->create('malware.pdf', 100, 'application/pdf');

    $response = $this->withActorToken($user)
        ->post("/v1/agent-applications/{$application->id}/documents", [
            'document_type' => 'national_id',
            'file'          => $file,
        ]);

    putenv('AGENT_VIRUS_SCAN_STUB_RESULT=');

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'DOCUMENT_VIRUS_DETECTED');

    $quarantined = DB::connection('platform')
        ->table('agent.agent_documents')
        ->where('application_id', $application->id)
        ->where('status', DocumentStatus::Quarantined->value)
        ->first();

    expect($quarantined)->not->toBeNull();
    expect($quarantined->storage_reference)->toBeNull();
    expect($quarantined->rejection_reason)->toBe('VIRUS_SCAN_FAILED');

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'agent.application.document_rejected')
        ->first();

    expect($outbox)->not->toBeNull();
    $payload = json_decode($outbox->payload, true);
    expect($payload['actor_type'])->toBe('system');
    expect($payload['reason'])->toBe('VIRUS_SCAN_FAILED');
});
