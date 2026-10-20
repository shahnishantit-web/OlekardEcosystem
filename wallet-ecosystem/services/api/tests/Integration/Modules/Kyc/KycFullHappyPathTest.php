<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T107 ————————————————————————————————————————————————

test('full happy path: start → personal info → upload identity doc → submit → approve', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.create', 'kyc.case.update');
    $admin    = $this->makeAdminWithPermission('kyc.case.approve', 'kyc.case.review');

    // 1. Start KYC case
    $startResponse = $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'happy-path-001'])
        ->postJson('/v1/kyc/cases');

    $startResponse->assertStatus(201);
    $caseId = $startResponse->json('data.case_id');
    expect($caseId)->not->toBeNull();

    // 2. Capture personal information
    $this->withCustomerToken($customer)
        ->putJson("/v1/kyc/cases/{$caseId}/personal-information", [
            'full_legal_name'    => 'Happy Path User',
            'date_of_birth'      => '1990-06-15',
            'nationality'        => 'BW',
            'national_id_type'   => 'national_id',
            'national_id_number' => 'HP-TEST-001',
            'address' => [
                'line_1'       => '1 Happy Street',
                'city'         => 'Gaborone',
                'postal_code'  => '0001',
                'country_code' => 'BW',
            ],
        ])->assertStatus(200);

    // 3. Upload identity document
    $file = UploadedFile::fake()->image('national_id.jpg')->size(100);
    $this->withCustomerToken($customer)
        ->postJson("/v1/kyc/cases/{$caseId}/documents", [
            'document_type' => 'national_id',
            'file'          => $file,
            'expires_on'    => now()->addYears(5)->format('Y-m-d'),
        ])->assertStatus(201);

    // 4. Submit
    $submitResponse = $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'happy-submit-001'])
        ->postJson("/v1/kyc/cases/{$caseId}/submit");

    $submitResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'under_review');

    // 5. Approve
    $approveResponse = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$caseId}/approve");

    $approveResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'approved');

    // Verify domain event emitted
    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'Kyc.Case.Approved')
        ->first();

    expect($outbox)->not->toBeNull();

    // Verify audit trail
    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.approved')
        ->where('resource_id', $caseId)
        ->first();

    expect($audit)->not->toBeNull();

    // expires_at is null when kyc.validity_period_days is TBD
    $case = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $caseId)
        ->first();

    // If TBD, expires_at is null (TBD guard active)
    // Verify verified_at is set regardless
    expect($case->verified_at)->not->toBeNull();
});
