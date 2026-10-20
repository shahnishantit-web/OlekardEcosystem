<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T110 ————————————————————————————————————————————————

test('submit → request-info → customer sees note → resubmit clears note → re-submit → approve', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.create', 'kyc.case.update', 'kyc.case.read');
    $admin    = $this->makeAdminWithPermission('kyc.case.approve', 'kyc.case.review');

    // Start
    $startResp = $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'ni-start-001'])
        ->postJson('/v1/kyc/cases');
    $caseId = $startResp->json('data.case_id');

    // Personal info + doc + submit
    $this->withCustomerToken($customer)
        ->putJson("/v1/kyc/cases/{$caseId}/personal-information", [
            'full_legal_name'    => 'Needs Info User',
            'date_of_birth'      => '1991-04-22',
            'nationality'        => 'BW',
            'national_id_type'   => 'national_id',
            'national_id_number' => 'NI-TEST-001',
            'address' => [
                'line_1'       => '3 Info Lane',
                'city'         => 'Francistown',
                'postal_code'  => '0003',
                'country_code' => 'BW',
            ],
        ])->assertStatus(200);

    $this->withCustomerToken($customer)
        ->postJson("/v1/kyc/cases/{$caseId}/documents", [
            'document_type' => 'national_id',
            'file'          => UploadedFile::fake()->image('nid.jpg')->size(100),
            'expires_on'    => now()->addYears(4)->format('Y-m-d'),
        ])->assertStatus(201);

    $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'ni-submit-001'])
        ->postJson("/v1/kyc/cases/{$caseId}/submit")
        ->assertStatus(200);

    // Request info
    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$caseId}/request-info", [
            'info_request_note' => 'Please upload a clearer selfie.',
        ])->assertStatus(200);

    // Customer sees info_request_note
    $statusResp = $this->withCustomerToken($customer)
        ->getJson('/v1/kyc/cases/my');
    $statusResp->assertStatus(200)
        ->assertJsonPath('data.status', 'needs_info')
        ->assertJsonPath('data.info_request_note', 'Please upload a clearer selfie.');

    // Resubmit
    $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'ni-resub-001'])
        ->postJson("/v1/kyc/cases/{$caseId}/resubmit")
        ->assertStatus(200);

    // Note is cleared after resubmit
    $noteAfterResub = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $caseId)
        ->value('info_request_note');
    expect($noteAfterResub)->toBeNull();

    // Re-upload + re-submit + approve
    $this->withCustomerToken($customer)
        ->postJson("/v1/kyc/cases/{$caseId}/documents", [
            'document_type' => 'selfie',
            'file'          => UploadedFile::fake()->image('selfie2.jpg')->size(100),
        ])->assertStatus(201);

    $this->withCustomerToken($customer)
        ->postJson("/v1/kyc/cases/{$caseId}/documents", [
            'document_type' => 'national_id',
            'file'          => UploadedFile::fake()->image('nid2.jpg')->size(100),
            'expires_on'    => now()->addYears(4)->format('Y-m-d'),
        ])->assertStatus(201);

    $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'ni-submit-002'])
        ->postJson("/v1/kyc/cases/{$caseId}/submit")
        ->assertStatus(200);

    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$caseId}/approve")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'approved');
});
