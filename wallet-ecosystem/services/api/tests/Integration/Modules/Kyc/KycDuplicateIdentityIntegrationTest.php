<?php

declare(strict_types=1);

use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T109 ————————————————————————————————————————————————

test('User B blocked with 409 after User A is approved with same national ID', function () {
    $nationalId = 'INTEG-DUP-001';
    $hash       = PiiMasker::hashNationalId($nationalId);

    // User A: create approved case
    $userA = $this->makeCustomerWithPermission('kyc.case.update');
    $caseA = $this->kycCreateCase($userA->id);
    $this->kycAddPersonalInfo($caseA->id, $hash);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $caseA->id)
        ->update(['status' => 'approved']);

    // User B: try same national ID
    $userB = $this->makeCustomerWithPermission('kyc.case.update');
    $caseB = $this->kycCreateCase($userB->id);

    $response = $this->withCustomerToken($userB)
        ->putJson("/v1/kyc/cases/{$caseB->id}/personal-information", [
            'full_legal_name'    => 'User B',
            'date_of_birth'      => '1992-03-10',
            'nationality'        => 'BW',
            'national_id_type'   => 'national_id',
            'national_id_number' => $nationalId,
            'address' => [
                'line_1'       => '2 Duplicate Ave',
                'city'         => 'Gaborone',
                'postal_code'  => '0001',
                'country_code' => 'BW',
            ],
        ]);

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'DUPLICATE_IDENTITY_DETECTED');

    // Audit event written
    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.duplicate-identity.detected')
        ->first();
    expect($audit)->not->toBeNull();

    // No personal_info record created for User B
    $count = DB::connection('kyc')
        ->table('kyc.kyc_personal_info')
        ->where('kyc_case_id', $caseB->id)
        ->count();
    expect($count)->toBe(0);
});
