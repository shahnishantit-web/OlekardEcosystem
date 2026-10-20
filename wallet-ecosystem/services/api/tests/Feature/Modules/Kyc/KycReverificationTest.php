<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T101 ————————————————————————————————————————————————

test('POST /reverify returns 200 initiated with incremented attempt_number', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.manage');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update([
            'status'      => 'approved',
            'verified_at' => now()->subDays(30),
            'updated_at'  => now(),
        ]);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/reverify");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'initiated')
        ->assertJsonPath('data.attempt_number', 2);
});

test('POST /reverify soft-deletes all active documents', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.manage');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    $this->kycUploadDocument($case->id, 'national_id');

    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update([
            'status'      => 'approved',
            'verified_at' => now()->subDays(30),
            'updated_at'  => now(),
        ]);

    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/reverify")
        ->assertStatus(200);

    $activeDocCount = DB::connection('kyc')
        ->table('kyc.kyc_documents')
        ->where('kyc_case_id', $case->id)
        ->whereNull('deleted_at')
        ->count();

    expect($activeDocCount)->toBe(0);
});

test('POST /reverify emits Kyc.Case.ReverificationTriggered outbox event', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.manage');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update(['status' => 'approved', 'updated_at' => now()]);

    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/reverify")
        ->assertStatus(200);

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'Kyc.Case.ReverificationTriggered')
        ->first();

    expect($outbox)->not->toBeNull();
});

test('POST /reverify emits kyc.case.reverification-triggered audit event', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.manage');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update(['status' => 'expired', 'updated_at' => now()]);

    $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/reverify")
        ->assertStatus(200);

    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.reverification-triggered')
        ->where('resource_id', $case->id)
        ->first();

    expect($audit)->not->toBeNull();
});

test('POST /reverify returns 422 when case is not approved or expired', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.manage');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    $response = $this->withActorToken($admin)
        ->postJson("/v1/kyc/cases/{$case->id}/reverify");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CASE_STATUS');
});

test('POST /reverify with customer token returns 403 AUTHORIZATION_001', function () {
    $customer1 = $this->makeCustomerWithPermission('kyc.case.create');
    $case      = $this->kycCreateCase($customer1->id);

    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update(['status' => 'approved', 'updated_at' => now()]);

    $response = $this->withCustomerToken($customer1)
        ->postJson("/v1/kyc/cases/{$case->id}/reverify");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'AUTHORIZATION_001');
});
