<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T089 ————————————————————————————————————————————————

test('POST /resubmit after rejection returns 200 attempt_number=2 status=initiated', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.update');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'resub-001'));
    $this->kycUploadDocument($case->id, 'national_id');
    $this->kycSubmitCase($case->id);

    // Reject the case
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update([
            'status'           => 'rejected',
            'rejection_reason' => 'Blurry image.',
            'updated_at'       => now(),
        ]);

    $response = $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'resub-key-001'])
        ->postJson("/v1/kyc/cases/{$case->id}/resubmit");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'initiated')
        ->assertJsonPath('data.attempt_number', 2);
});

test('POST /resubmit soft-deletes all previous documents', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.update');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'resub-002'));
    $this->kycUploadDocument($case->id, 'national_id');
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update(['status' => 'rejected', 'updated_at' => now()]);

    $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'resub-key-002'])
        ->postJson("/v1/kyc/cases/{$case->id}/resubmit")
        ->assertStatus(200);

    $activeDocCount = DB::connection('kyc')
        ->table('kyc.kyc_documents')
        ->where('kyc_case_id', $case->id)
        ->whereNull('deleted_at')
        ->count();

    expect($activeDocCount)->toBe(0);
});

test('POST /resubmit emits kyc.case.resubmitted audit event (not a domain event)', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.update');
    $case     = $this->kycCreateCase($customer->id);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update(['status' => 'rejected', 'updated_at' => now()]);

    $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'resub-key-003'])
        ->postJson("/v1/kyc/cases/{$case->id}/resubmit")
        ->assertStatus(200);

    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.resubmitted')
        ->where('resource_id', $case->id)
        ->first();

    expect($audit)->not->toBeNull();

    // FR-E05-033 mandates audit only — no domain event
    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'Kyc.Case.Resubmitted')
        ->first();

    expect($outbox)->toBeNull();
});

test('POST /resubmit idempotent replay returns same 200 body', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.update');
    $case     = $this->kycCreateCase($customer->id);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update(['status' => 'needs_info', 'updated_at' => now()]);

    $r1 = $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'resub-idem-001'])
        ->postJson("/v1/kyc/cases/{$case->id}/resubmit");
    $r1->assertStatus(200);

    $r2 = $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'resub-idem-001'])
        ->postJson("/v1/kyc/cases/{$case->id}/resubmit");
    $r2->assertStatus(200);

    expect($r1->json('data.attempt_number'))->toBe($r2->json('data.attempt_number'));
});

test('POST /resubmit returns 422 when case is not rejected or needs_info', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.update');
    $case     = $this->kycCreateCase($customer->id);

    $response = $this->withCustomerToken($customer)
        ->withHeaders(['Idempotency-Key' => 'resub-wrong-001'])
        ->postJson("/v1/kyc/cases/{$case->id}/resubmit");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CASE_STATUS');
});
