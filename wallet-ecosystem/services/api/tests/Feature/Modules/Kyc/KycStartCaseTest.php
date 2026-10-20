<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

test('POST /v1/kyc/cases returns 201 with initiated case', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.create');

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'kyc-start-001'])
        ->postJson('/v1/kyc/cases');

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'initiated')
        ->assertJsonPath('data.attempt_number', 1)
        ->assertJsonStructure(['data' => ['case_id', 'status', 'attempt_number', 'created_at']]);
});

test('POST /v1/kyc/cases with same Idempotency-Key returns same case (idempotent replay)', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.create');

    $response1 = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'kyc-idem-001'])
        ->postJson('/v1/kyc/cases');

    $response1->assertStatus(201);
    $caseId1 = $response1->json('data.case_id');

    $response2 = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'kyc-idem-001'])
        ->postJson('/v1/kyc/cases');

    $response2->assertStatus(201);
    $caseId2 = $response2->json('data.case_id');

    expect($caseId1)->toBe($caseId2);

    $count = DB::connection('kyc')->table('kyc.kyc_cases')->where('user_id', $user->id)->count();
    expect($count)->toBe(1);
});

test('POST /v1/kyc/cases returns 409 KYC_CASE_ALREADY_OPEN when open case exists', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.create');
    $this->kycCreateCase($user->id, 'existing-key');

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'new-key-001'])
        ->postJson('/v1/kyc/cases');

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'KYC_CASE_ALREADY_OPEN');
});

test('POST /v1/kyc/cases returns 409 KYC_ALREADY_APPROVED when approved case exists', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.create');
    $case = $this->kycCreateCase($user->id, 'approved-key');
    DB::connection('kyc')->table('kyc.kyc_cases')->where('id', $case->id)->update(['status' => 'approved']);

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'new-key-002'])
        ->postJson('/v1/kyc/cases');

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'KYC_ALREADY_APPROVED');
});

test('POST /v1/kyc/cases allows start when prior case is expired (EC-08)', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.create');
    $case = $this->kycCreateCase($user->id, 'expired-key');
    DB::connection('kyc')->table('kyc.kyc_cases')->where('id', $case->id)->update(['status' => 'expired']);

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'after-expired-001'])
        ->postJson('/v1/kyc/cases');

    $response->assertStatus(201);
});

test('POST /v1/kyc/cases returns 401 without authentication', function () {
    $response = $this->withHeaders(['Idempotency-Key' => 'no-auth-001'])
        ->postJson('/v1/kyc/cases');

    $response->assertStatus(401);
});

test('POST /v1/kyc/cases returns 422 without Idempotency-Key header', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.create');

    $response = $this->withCustomerToken($user)
        ->postJson('/v1/kyc/cases');

    $response->assertStatus(422);
});

test('POST /v1/kyc/cases without kyc.case.create permission returns 403', function () {
    $user = $this->makeCustomerWithoutPermissions();

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'no-perm-001'])
        ->postJson('/v1/kyc/cases');

    $response->assertStatus(403);
});

test('successful start creates row in kyc.kyc_cases with correct fields', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.create');

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'db-assert-001'])
        ->postJson('/v1/kyc/cases');

    $response->assertStatus(201);
    $caseId = $response->json('data.case_id');

    $row = DB::connection('kyc')->table('kyc.kyc_cases')->find($caseId);
    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
    expect($row->status)->toBe('initiated');
    expect($row->attempt_number)->toBe(1);
});

test('successful start writes kyc.case.initiated audit event', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.create');

    $response = $this->withCustomerToken($user)
        ->withHeaders(['Idempotency-Key' => 'audit-001'])
        ->postJson('/v1/kyc/cases');

    $response->assertStatus(201);
    $caseId = $response->json('data.case_id');

    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.initiated')
        ->where('resource_id', $caseId)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->outcome)->toBe('success');
});
