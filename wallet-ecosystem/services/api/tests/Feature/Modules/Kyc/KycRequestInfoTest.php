<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T084 ————————————————————————————————————————————————

test('POST /request-info returns 200 needs_info with note stored', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/request-info", [
            'info_request_note' => 'Please provide a clearer selfie.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'needs_info');

    $note = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->value('info_request_note');

    expect($note)->toBe('Please provide a clearer selfie.');
});

test('POST /request-info note visible in response', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/request-info", [
            'info_request_note' => 'Missing utility bill.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.info_request_note', 'Missing utility bill.');
});

test('POST /request-info returns 422 when info_request_note is empty', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/request-info", [
            'info_request_note' => '',
        ]);

    $response->assertStatus(422);
});

test('POST /request-info returns 422 INVALID_CASE_STATUS when case is not under_review', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/request-info", [
            'info_request_note' => 'Need more info.',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CASE_STATUS');
});
