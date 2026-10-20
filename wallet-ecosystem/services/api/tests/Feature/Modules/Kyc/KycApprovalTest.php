<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T082 ————————————————————————————————————————————————

test('POST /approve returns 200 with status approved', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.approve');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/approve");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'approved');
});

test('POST /approve sets verified_at', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.approve');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/approve")
        ->assertStatus(200);

    $verifiedAt = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->value('verified_at');

    expect($verifiedAt)->not->toBeNull();
});

test('POST /approve emits kyc.case.approved audit event', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.approve');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/approve")
        ->assertStatus(200);

    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.approved')
        ->where('resource_id', $case->id)
        ->first();

    expect($audit)->not->toBeNull();
});

test('POST /approve emits Kyc.Case.Approved outbox event', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.approve');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/approve")
        ->assertStatus(200);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'Kyc.Case.Approved')
        ->first();

    expect($outbox)->not->toBeNull();
});

test('POST /approve returns 422 INVALID_CASE_STATUS when case is not under_review', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.approve');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id); // initiated, not under_review

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/approve");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CASE_STATUS');
});

test('POST /approve without kyc.case.approve permission returns 403', function () {
    $reviewer = $this->makeAdminWithPermission('kyc.case.review'); // review but NOT approve
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    $response = $this->withActorToken($reviewer)
        ->postJson("/v1/kyc/cases/{$case->id}/approve");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'AUTHORIZATION_001');
});
