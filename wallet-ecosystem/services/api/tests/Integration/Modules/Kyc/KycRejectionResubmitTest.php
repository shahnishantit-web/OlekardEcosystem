<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T108 ————————————————————————————————————————————————

test('rejection → resubmit → re-upload → re-submit → approve lifecycle', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.create', 'kyc.case.update', 'kyc.case.read');
    $admin    = $this->makeAdminWithPermission('kyc.case.approve', 'kyc.case.review');

    // Start
    $startResp = $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'rr-start-001'])
        ->postJson('/v1/kyc/cases');
    $startResp->assertStatus(201);
    $caseId = $startResp->json('data.case_id');

    // Personal info
    $this->withCustomerToken($customer)
        ->putJson("/v1/kyc/cases/{$caseId}/personal-information", [
            'full_legal_name'    => 'Resubmit User',
            'date_of_birth'      => '1985-10-20',
            'nationality'        => 'ZW',
            'national_id_type'   => 'passport',
            'national_id_number' => 'ZW-RESUB-001',
            'address' => [
                'line_1'       => '5 Resubmit Ave',
                'city'         => 'Harare',
                'postal_code'  => 'HRE001',
                'country_code' => 'ZW',
            ],
        ])->assertStatus(200);

    // Upload doc attempt 1
    $this->withCustomerToken($customer)
        ->postJson("/v1/kyc/cases/{$caseId}/documents", [
            'document_type' => 'passport',
            'file'          => UploadedFile::fake()->image('passport1.jpg')->size(100),
            'expires_on'    => now()->addYears(5)->format('Y-m-d'),
        ])->assertStatus(201);

    // Submit
    $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'rr-submit-001'])
        ->postJson("/v1/kyc/cases/{$caseId}/submit")
        ->assertStatus(200);

    // Reject
    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$caseId}/reject", [
            'rejection_reason' => 'Passport image too dark.',
        ])->assertStatus(200);

    // Check customer can see rejection reason
    $statusResp = $this->withCustomerToken($customer)
        ->getJson('/v1/kyc/cases/my');
    $statusResp->assertStatus(200)
        ->assertJsonPath('data.rejection_reason', 'Passport image too dark.');

    // Resubmit
    $resubResp = $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'rr-resub-001'])
        ->postJson("/v1/kyc/cases/{$caseId}/resubmit");
    $resubResp->assertStatus(200)
        ->assertJsonPath('data.attempt_number', 2);

    // Old docs must be soft-deleted
    $oldDocCount = DB::connection('kyc')
        ->table('kyc.kyc_documents')
        ->where('kyc_case_id', $caseId)
        ->whereNotNull('deleted_at')
        ->count();
    expect($oldDocCount)->toBeGreaterThanOrEqual(1);

    // Re-upload
    $this->withCustomerToken($customer)
        ->postJson("/v1/kyc/cases/{$caseId}/documents", [
            'document_type' => 'passport',
            'file'          => UploadedFile::fake()->image('passport2.jpg')->size(100),
            'expires_on'    => now()->addYears(5)->format('Y-m-d'),
        ])->assertStatus(201);

    // Re-submit
    $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'rr-submit-002'])
        ->postJson("/v1/kyc/cases/{$caseId}/submit")
        ->assertStatus(200);

    // Approve
    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$caseId}/approve")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'approved');

    $finalAttempt = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $caseId)
        ->value('attempt_number');

    expect($finalAttempt)->toBe(2);
});
