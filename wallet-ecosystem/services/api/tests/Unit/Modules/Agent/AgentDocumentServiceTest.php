<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\DocumentStatus;
use App\Modules\Agent\Domain\Enums\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T050 ————————————————————————————————————————————————

test('upload creates quarantined record without storage call when virus scan fails', function () {
    putenv('AGENT_VIRUS_SCAN_STUB_RESULT=infected');

    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);
    $file        = UploadedFile::fake()->create('bad.pdf', 50, 'application/pdf');

    $response = $this->withActorToken($user)
        ->post("/v1/agent-applications/{$application->id}/documents", [
            'document_type' => 'national_id',
            'file'          => $file,
        ]);

    putenv('AGENT_VIRUS_SCAN_STUB_RESULT=');

    $response->assertStatus(422);

    $quarantined = DB::connection('platform')
        ->table('agent.agent_documents')
        ->where('application_id', $application->id)
        ->where('status', DocumentStatus::Quarantined->value)
        ->first();

    expect($quarantined)->not->toBeNull();
    expect($quarantined->storage_reference)->toBeNull();
});

test('upload supersedes prior document of same type with SUPERSEDED reason', function () {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);

    $file1 = UploadedFile::fake()->create('doc1.pdf', 50, 'application/pdf');
    $file2 = UploadedFile::fake()->create('doc2.pdf', 50, 'application/pdf');

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

    $prior = DB::connection('platform')
        ->table('agent.agent_documents')
        ->where('application_id', $application->id)
        ->where('status', DocumentStatus::Rejected->value)
        ->where('rejection_reason', 'SUPERSEDED')
        ->first();

    expect($prior)->not->toBeNull();
});

test('upload throws INVALID_DOCUMENT_FORMAT for unsupported mime type', function () {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);
    $file        = UploadedFile::fake()->create('doc.docx', 50, 'application/msword');

    $response = $this->withActorToken($user)
        ->post("/v1/agent-applications/{$application->id}/documents", [
            'document_type' => 'national_id',
            'file'          => $file,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_DOCUMENT_FORMAT');
});

test('upload throws DOCUMENT_TOO_LARGE when file exceeds config limit', function () {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id);
    $file        = UploadedFile::fake()->create('large.pdf', 11 * 1024, 'application/pdf');

    $response = $this->withActorToken($user)
        ->post("/v1/agent-applications/{$application->id}/documents", [
            'document_type' => 'national_id',
            'file'          => $file,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'DOCUMENT_TOO_LARGE');
});
