<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T081 ————————————————————————————————————————————————

test('GET /cases returns paginated list for compliance officer', function () {
    $admin = $this->makeAdminWithPermission('kyc.case.review');

    // Create 3 cases for different users
    for ($i = 0; $i < 3; $i++) {
        $u = $this->makeCustomerWithPermission('kyc.case.create');
        $this->kycCreateCase($u->id);
    }

    $response = $this->withActorToken($admin)
        ->getJson('/v1/kyc/cases');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page']]);
});

test('GET /cases?status=under_review returns only under_review cases', function () {
    $admin = $this->makeAdminWithPermission('kyc.case.review');

    $u1 = $this->makeCustomerWithPermission('kyc.case.create');
    $u2 = $this->makeCustomerWithPermission('kyc.case.create');
    $c1 = $this->kycCreateCase($u1->id);
    $c2 = $this->kycCreateCase($u2->id);

    DB::connection('kyc')->table('kyc.kyc_cases')->where('id', $c1->id)->update(['status' => 'under_review']);
    // c2 stays initiated

    $response = $this->withActorToken($admin)
        ->getJson('/v1/kyc/cases?status=under_review');

    $response->assertStatus(200);

    $data    = $response->json('data');
    $caseIds = array_column($data, 'case_id');

    expect($caseIds)->toContain($c1->id);
    expect($caseIds)->not->toContain($c2->id);
});

test('GET /cases with customer token returns 403', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.create');

    $response = $this->withCustomerToken($customer)
        ->getJson('/v1/kyc/cases');

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'AUTHORIZATION_001');
});

test('GET /cases with admin missing kyc.case.review returns 403', function () {
    $admin = $this->makeAdminWithPermission('kyc.case.approve'); // has approve but not review

    $response = $this->withActorToken($admin)
        ->getJson('/v1/kyc/cases');

    $response->assertStatus(403);
});
