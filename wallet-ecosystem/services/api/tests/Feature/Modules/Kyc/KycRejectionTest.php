<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T083 ————————————————————————————————————————————————

test('POST /reject returns 200 rejected with rejection_reason stored', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/reject", [
            'rejection_reason' => 'Documents were unclear and unreadable.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'rejected');

    $storedReason = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->value('rejection_reason');

    expect($storedReason)->toBe('Documents were unclear and unreadable.');
});

test('POST /reject returns 400 when rejection_reason is empty', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/reject", [
            'rejection_reason' => '',
        ]);

    $response->assertStatus(422);
});

test('POST /reject returns 400 when rejection_reason is absent', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/reject", []);

    $response->assertStatus(422);
});

test('POST /reject returns 422 INVALID_CASE_STATUS when case is not under_review', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id); // initiated

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/reject", [
            'rejection_reason' => 'Invalid documents.',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CASE_STATUS');
});

test('POST /reject rejection_reason returned in response', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/reject", [
            'rejection_reason' => 'Expired passport submitted.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.rejection_reason', 'Expired passport submitted.');
});
