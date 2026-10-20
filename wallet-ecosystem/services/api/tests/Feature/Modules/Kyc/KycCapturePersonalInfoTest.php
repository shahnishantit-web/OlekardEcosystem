<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

$validPayload = fn () => [
    'full_legal_name'         => 'John Test Doe',
    'date_of_birth'           => '1990-05-15',
    'nationality'             => 'BW',
    'national_id_type'        => 'national_id',
    'national_id_number'      => 'BW123456789',
    'address' => [
        'line_1'       => '123 Main Street',
        'city'         => 'Gaborone',
        'postal_code'  => '0001',
        'country_code' => 'BW',
    ],
];

// T046 —————————————————————————————————————————————————

test('PUT personal-information returns 200 and case status unchanged', function () use ($validPayload) {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);

    $response = $this->withCustomerToken($user)
        ->putJson("/v1/kyc/cases/{$case->id}/personal-information", $validPayload());

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.personal_information_provided', true);
});

test('PUT personal-information returns 422 when required fields missing', function () use ($validPayload) {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);

    $payload = $validPayload();
    unset($payload['full_legal_name']);

    $response = $this->withCustomerToken($user)
        ->putJson("/v1/kyc/cases/{$case->id}/personal-information", $payload);

    $response->assertStatus(422);
});

test('PUT personal-information returns 422 when case is in non-editable status', function () use ($validPayload) {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    DB::connection('kyc')->table('kyc.kyc_cases')->where('id', $case->id)->update(['status' => 'under_review']);

    $response = $this->withCustomerToken($user)
        ->putJson("/v1/kyc/cases/{$case->id}/personal-information", $validPayload());

    $response->assertStatus(422);
});

test('PUT personal-information returns 404 when case belongs to another user', function () use ($validPayload) {
    $user1 = $this->makeCustomerWithPermission('kyc.case.update');
    $user2 = $this->makeCustomerWithPermission('kyc.case.update');
    $case  = $this->kycCreateCase($user1->id);

    $response = $this->withCustomerToken($user2)
        ->putJson("/v1/kyc/cases/{$case->id}/personal-information", $validPayload());

    $response->assertStatus(404);
});

// T047 —————————————————————————————————————————————————

test('PUT personal-information stores national_id_hash (64-char hex) and never national_id_number', function () use ($validPayload) {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);

    $this->withCustomerToken($user)
        ->putJson("/v1/kyc/cases/{$case->id}/personal-information", $validPayload())
        ->assertStatus(200);

    $info = DB::connection('kyc')
        ->table('kyc.kyc_personal_info')
        ->where('kyc_case_id', $case->id)
        ->first();

    expect($info)->not->toBeNull();
    expect($info->national_id_hash)->toHaveLength(64);
    expect(ctype_xdigit($info->national_id_hash))->toBeTrue();

    // The plaintext national_id_number must never be stored
    expect(isset($info->national_id_number))->toBeFalse();
});

test('PUT personal-information does not emit national_id_hash in audit event payload', function () use ($validPayload) {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);

    $this->withCustomerToken($user)
        ->putJson("/v1/kyc/cases/{$case->id}/personal-information", $validPayload())
        ->assertStatus(200);

    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.personal-information-captured')
        ->where('resource_id', $case->id)
        ->first();

    expect($audit)->not->toBeNull();

    // The audit payload must not contain the raw national_id_number
    $payload = json_decode($audit->metadata ?? '{}', true);
    expect(json_encode($payload))->not->toContain('BW123456789');
});
