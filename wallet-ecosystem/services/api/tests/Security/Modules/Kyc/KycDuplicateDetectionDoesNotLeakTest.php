<?php

declare(strict_types=1);

use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T106 ————————————————————————————————————————————————

test('409 DUPLICATE_IDENTITY_DETECTED response body does not reveal blocking user info', function () {
    $nationalId = 'BW-LEAK-TEST-001';
    $hash       = PiiMasker::hashNationalId($nationalId);

    // User A: approved case with this national ID
    $userA = $this->makeCustomerWithPermission('kyc.case.update');
    $caseA = $this->kycCreateCase($userA->id);
    $this->kycAddPersonalInfo($caseA->id, $hash);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $caseA->id)
        ->update(['status' => 'approved']);

    // User B: submits same national ID → should get 409
    $userB = $this->makeCustomerWithPermission('kyc.case.update');
    $caseB = $this->kycCreateCase($userB->id);

    $response = $this->withCustomerToken($userB)
        ->putJson("/v1/kyc/cases/{$caseB->id}/personal-information", [
            'full_legal_name'    => 'Attacker Test',
            'date_of_birth'      => '1990-01-01',
            'nationality'        => 'BW',
            'national_id_type'   => 'national_id',
            'national_id_number' => $nationalId,
            'address' => [
                'line_1'       => '1 Test St',
                'city'         => 'Gaborone',
                'postal_code'  => '0001',
                'country_code' => 'BW',
            ],
        ]);

    $response->assertStatus(409);

    $body = json_encode($response->json());

    // Response must not leak user A's identity
    expect($body)->not->toContain($userA->id);
    expect($body)->not->toContain($userA->email);
    expect($body)->not->toContain($caseA->id);
    expect($body)->not->toContain($nationalId);
    expect($body)->not->toContain($hash);
});
