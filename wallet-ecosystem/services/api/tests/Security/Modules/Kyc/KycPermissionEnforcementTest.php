<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T105 ————————————————————————————————————————————————

test('CUSTOMER token on GET /cases returns 403 AUTHORIZATION_001', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.create');

    $this->withCustomerToken($customer)
        ->getJson('/v1/kyc/cases')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'AUTHORIZATION_001');
});

test('CUSTOMER token on POST /cases/{id}/approve returns 403 AUTHORIZATION_001', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    $this->withCustomerToken($customer)
        ->postJson("/v1/kyc/cases/{$case->id}/approve")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'AUTHORIZATION_001');
});

test('Admin without kyc.case.approve returns 403 AUTHORIZATION_001 on approve', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review'); // review only, no approve
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/approve")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'AUTHORIZATION_001');
});

test('unauthenticated request on KYC routes returns 401', function () {
    $this->getJson('/v1/kyc/cases')
        ->assertStatus(401);

    $this->postJson('/v1/kyc/cases', ['Idempotency-Key' => 'no-auth'])
        ->assertStatus(401);
});
