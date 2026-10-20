<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use App\Modules\Kyc\Infrastructure\Jobs\PerformAutomatedVerificationJob;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T065 ————————————————————————————————————————————————

test('POST /submit returns 200 under_review when case is complete', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'some-id'));
    $this->kycUploadDocument($case->id, 'national_id');

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'submit-001'])
        ->postJson("/v1/kyc/cases/{$case->id}/submit");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'under_review');
});

test('POST /submit sets submitted_at timestamp', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'some-id-2'));
    $this->kycUploadDocument($case->id, 'national_id');

    $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'submit-002'])
        ->postJson("/v1/kyc/cases/{$case->id}/submit")
        ->assertStatus(200);

    $submittedAt = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->value('submitted_at');

    expect($submittedAt)->not->toBeNull();
});

test('POST /submit returns 422 CASE_INCOMPLETE when no personal info', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycUploadDocument($case->id, 'national_id');

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'submit-003'])
        ->postJson("/v1/kyc/cases/{$case->id}/submit");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'CASE_INCOMPLETE');
});

test('POST /submit returns 422 CASE_INCOMPLETE when no identity document', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'some-id-3'));

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'submit-004'])
        ->postJson("/v1/kyc/cases/{$case->id}/submit");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'CASE_INCOMPLETE');
});

test('POST /submit returns 422 INVALID_CASE_STATUS when already under_review', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycSubmitCase($case->id);

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'submit-005'])
        ->postJson("/v1/kyc/cases/{$case->id}/submit");

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CASE_STATUS');
});

test('POST /submit idempotent replay returns same 200 body', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'some-id-4'));
    $this->kycUploadDocument($case->id, 'national_id');

    $r1 = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'submit-idem-001'])
        ->postJson("/v1/kyc/cases/{$case->id}/submit");
    $r1->assertStatus(200);

    $r2 = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'submit-idem-001'])
        ->postJson("/v1/kyc/cases/{$case->id}/submit");
    $r2->assertStatus(200);

    expect($r1->json('data.status'))->toBe($r2->json('data.status'));
});

// T066 ————————————————————————————————————————————————

test('POST /submit does not dispatch PerformAutomatedVerificationJob when auto_verify_enabled false', function () {
    Bus::fake();

    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'some-id-5'));
    $this->kycUploadDocument($case->id, 'national_id');

    // Ensure auto_verify_enabled = false (default seeded value)
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('key', 'kyc.auto_verify_enabled')
        ->update(['value' => 'false', 'is_tbd' => false]);

    $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'submit-auto-false'])
        ->postJson("/v1/kyc/cases/{$case->id}/submit")
        ->assertStatus(200);

    Bus::assertNotDispatched(PerformAutomatedVerificationJob::class);
});

test('POST /submit writes kyc.case.submitted audit event (not a domain event)', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'some-id-6'));
    $this->kycUploadDocument($case->id, 'national_id');

    $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'submit-audit-001'])
        ->postJson("/v1/kyc/cases/{$case->id}/submit")
        ->assertStatus(200);

    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.submitted')
        ->where('resource_id', $case->id)
        ->first();

    expect($audit)->not->toBeNull();

    // Must NOT emit a domain event for submit (FR-E05-019: audit only)
    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'Kyc.Case.Submitted')
        ->first();

    expect($outbox)->toBeNull();
});
