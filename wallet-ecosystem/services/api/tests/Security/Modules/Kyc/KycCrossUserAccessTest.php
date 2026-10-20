<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T103 ————————————————————————————————————————————————

test('GET /cases/{id} returns 404 when case belongs to another user (not 403)', function () {
    $userA = $this->makeCustomerWithPermission('kyc.case.read');
    $userB = $this->makeCustomerWithPermission('kyc.case.read');

    $caseA = $this->kycCreateCase($userA->id);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $caseA->id)
        ->update(['status' => 'approved', 'updated_at' => now()]);

    $response = $this->withCustomerToken($userB)
        ->getJson("/v1/kyc/cases/{$caseA->id}");

    // Must be 404, not 403 — to avoid leaking that the case exists
    $response->assertStatus(404);
});

test('PUT personal-information for another user returns 404', function () {
    $userA = $this->makeCustomerWithPermission('kyc.case.update');
    $userB = $this->makeCustomerWithPermission('kyc.case.update');
    $caseA = $this->kycCreateCase($userA->id);

    $response = $this->withCustomerToken($userB)
        ->putJson("/v1/kyc/cases/{$caseA->id}/personal-information", [
            'full_legal_name'    => 'Attacker',
            'date_of_birth'      => '1990-01-01',
            'nationality'        => 'BW',
            'national_id_type'   => 'national_id',
            'national_id_number' => 'FAKE999',
            'address' => [
                'line_1'       => 'Attacker Lane',
                'city'         => 'Nowhere',
                'postal_code'  => '0000',
                'country_code' => 'BW',
            ],
        ]);

    $response->assertStatus(404);
});

test('404 response body reveals nothing about the real case owner', function () {
    $userA = $this->makeCustomerWithPermission('kyc.case.read');
    $userB = $this->makeCustomerWithPermission('kyc.case.read');
    $caseA = $this->kycCreateCase($userA->id);

    $response = $this->withCustomerToken($userB)
        ->getJson("/v1/kyc/cases/{$caseA->id}");

    $responseBody = json_encode($response->json());

    expect($responseBody)->not->toContain($userA->id);
    expect($responseBody)->not->toContain($userA->email);
});
