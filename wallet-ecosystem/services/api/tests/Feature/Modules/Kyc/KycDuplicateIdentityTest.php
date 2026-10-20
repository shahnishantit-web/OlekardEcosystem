<?php

declare(strict_types=1);

use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

$personalInfoPayload = fn (string $nationalId = 'BW999000111') => [
    'full_legal_name'    => 'Duplicate Test User',
    'date_of_birth'      => '1985-03-20',
    'nationality'        => 'BW',
    'national_id_type'   => 'national_id',
    'national_id_number' => $nationalId,
    'address' => [
        'line_1'       => '99 Test Ave',
        'city'         => 'Maun',
        'postal_code'  => '0002',
        'country_code' => 'BW',
    ],
];

// T048 ————————————————————————————————————————————————

test('409 DUPLICATE_IDENTITY_DETECTED when approved case already has the same national ID', function () use ($personalInfoPayload) {
    $nationalId = 'BW-DUP-001';
    $hash       = PiiMasker::hashNationalId($nationalId);

    // User A: create approved case with personal info
    $userA = $this->makeCustomerWithPermission('kyc.case.update');
    $caseA = $this->kycCreateCase($userA->id);
    $this->kycAddPersonalInfo($caseA->id, $hash);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $caseA->id)
        ->update(['status' => 'approved']);

    // User B: tries to submit same national ID
    $userB = $this->makeCustomerWithPermission('kyc.case.update');
    $caseB = $this->kycCreateCase($userB->id);

    $response = $this->withCustomerToken($userB)
        ->putJson("/v1/kyc/cases/{$caseB->id}/personal-information", $personalInfoPayload($nationalId));

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'DUPLICATE_IDENTITY_DETECTED');
});

test('no kyc_personal_info row created for case B on duplicate detection', function () use ($personalInfoPayload) {
    $nationalId = 'BW-DUP-002';
    $hash       = PiiMasker::hashNationalId($nationalId);

    $userA = $this->makeCustomerWithPermission('kyc.case.update');
    $caseA = $this->kycCreateCase($userA->id);
    $this->kycAddPersonalInfo($caseA->id, $hash);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $caseA->id)
        ->update(['status' => 'approved']);

    $userB = $this->makeCustomerWithPermission('kyc.case.update');
    $caseB = $this->kycCreateCase($userB->id);

    $this->withCustomerToken($userB)
        ->putJson("/v1/kyc/cases/{$caseB->id}/personal-information", $personalInfoPayload($nationalId))
        ->assertStatus(409);

    $count = DB::connection('kyc')
        ->table('kyc.kyc_personal_info')
        ->where('kyc_case_id', $caseB->id)
        ->count();

    expect($count)->toBe(0);
});

test('duplicate detection emits kyc.duplicate-identity.detected audit event', function () use ($personalInfoPayload) {
    $nationalId = 'BW-DUP-003';
    $hash       = PiiMasker::hashNationalId($nationalId);

    $userA = $this->makeCustomerWithPermission('kyc.case.update');
    $caseA = $this->kycCreateCase($userA->id);
    $this->kycAddPersonalInfo($caseA->id, $hash);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $caseA->id)
        ->update(['status' => 'approved']);

    $userB = $this->makeCustomerWithPermission('kyc.case.update');
    $caseB = $this->kycCreateCase($userB->id);

    $this->withCustomerToken($userB)
        ->putJson("/v1/kyc/cases/{$caseB->id}/personal-information", $personalInfoPayload($nationalId))
        ->assertStatus(409);

    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.duplicate-identity.detected')
        ->first();

    expect($audit)->not->toBeNull();
});

// T049 ————————————————————————————————————————————————

test('same national ID allowed when prior case is rejected (200)', function () use ($personalInfoPayload) {
    $nationalId = 'BW-DUP-REJECT';
    $hash       = PiiMasker::hashNationalId($nationalId);

    $userA = $this->makeCustomerWithPermission('kyc.case.update');
    $caseA = $this->kycCreateCase($userA->id);
    $this->kycAddPersonalInfo($caseA->id, $hash);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $caseA->id)
        ->update(['status' => 'rejected']);

    $userB = $this->makeCustomerWithPermission('kyc.case.update');
    $caseB = $this->kycCreateCase($userB->id);

    $response = $this->withCustomerToken($userB)
        ->putJson("/v1/kyc/cases/{$caseB->id}/personal-information", $personalInfoPayload($nationalId));

    $response->assertStatus(200);
});

test('same national ID allowed when prior case is expired (200)', function () use ($personalInfoPayload) {
    $nationalId = 'BW-DUP-EXPIRED';
    $hash       = PiiMasker::hashNationalId($nationalId);

    $userA = $this->makeCustomerWithPermission('kyc.case.update');
    $caseA = $this->kycCreateCase($userA->id);
    $this->kycAddPersonalInfo($caseA->id, $hash);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $caseA->id)
        ->update(['status' => 'expired']);

    $userB = $this->makeCustomerWithPermission('kyc.case.update');
    $caseB = $this->kycCreateCase($userB->id);

    $response = $this->withCustomerToken($userB)
        ->putJson("/v1/kyc/cases/{$caseB->id}/personal-information", $personalInfoPayload($nationalId));

    $response->assertStatus(200);
});
