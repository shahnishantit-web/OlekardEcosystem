<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T092 ————————————————————————————————————————————————

test('upload utility_bill without expires_on returns 201', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $file = UploadedFile::fake()->image('bill.jpg')->size(100);

    $response = $this->withCustomerToken($user)
        ->postJson("/v1/kyc/cases/{$case->id}/documents", [
            'document_type' => 'utility_bill',
            'file'          => $file,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.document_type', 'utility_bill');
});

test('upload utility_bill with past expires_on is accepted (no expiry check for utility_bill)', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $file = UploadedFile::fake()->image('bill.jpg')->size(100);

    $response = $this->withCustomerToken($user)
        ->postJson("/v1/kyc/cases/{$case->id}/documents", [
            'document_type' => 'utility_bill',
            'file'          => $file,
            'expires_on'    => '2020-01-01',
        ]);

    // utility_bill skips expiry validation — should succeed
    $response->assertStatus(201);
});

test('upload utility_bill supersession: second upload soft-deletes first', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);

    $file1 = UploadedFile::fake()->image('bill1.jpg')->size(100);
    $file2 = UploadedFile::fake()->image('bill2.jpg')->size(100);

    $this->withCustomerToken($user)
        ->postJson("/v1/kyc/cases/{$case->id}/documents", [
            'document_type' => 'utility_bill',
            'file'          => $file1,
        ])->assertStatus(201);

    $this->withCustomerToken($user)
        ->postJson("/v1/kyc/cases/{$case->id}/documents", [
            'document_type' => 'utility_bill',
            'file'          => $file2,
        ])->assertStatus(201);

    $activeCount = DB::connection('kyc')
        ->table('kyc.kyc_documents')
        ->where('kyc_case_id', $case->id)
        ->where('document_type', 'utility_bill')
        ->whereNull('deleted_at')
        ->count();

    expect($activeCount)->toBe(1);
});
