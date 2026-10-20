<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T060 — validate-format ——————————————————————————————

test('POST validate-format with valid JPEG returns 200 valid:true', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $file = UploadedFile::fake()->image('doc.jpg')->size(100);

    $response = $this->withCustomerToken($user)
        ->postJson('/v1/kyc/documents/validate-format', ['file' => $file]);

    $response->assertStatus(200)
        ->assertJsonPath('data.valid', true);
});

test('POST validate-format with unsupported MIME returns 200 valid:false', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $file = UploadedFile::fake()->create('doc.bmp', 100, 'image/bmp');

    $response = $this->withCustomerToken($user)
        ->postJson('/v1/kyc/documents/validate-format', ['file' => $file]);

    $response->assertStatus(200)
        ->assertJsonPath('data.valid', false);
});

test('validate-format calls create zero kyc_documents rows', function () {
    $user  = $this->makeCustomerWithPermission('kyc.case.update');
    $file  = UploadedFile::fake()->image('doc.jpg')->size(100);
    $count = DB::connection('kyc')->table('kyc.kyc_documents')->count();

    $this->withCustomerToken($user)
        ->postJson('/v1/kyc/documents/validate-format', ['file' => $file]);

    expect(DB::connection('kyc')->table('kyc.kyc_documents')->count())->toBe($count);
});

// T061 — validate-expiry ——————————————————————————————

test('POST validate-expiry with future date returns 200 valid:true', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');

    $response = $this->withCustomerToken($user)
        ->postJson('/v1/kyc/documents/validate-expiry', [
            'expires_on' => now()->addYears(2)->format('Y-m-d'),
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.valid', true);
});

test('POST validate-expiry with past date returns 200 valid:false with DOCUMENT_EXPIRED', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');

    $response = $this->withCustomerToken($user)
        ->postJson('/v1/kyc/documents/validate-expiry', [
            'expires_on' => '2020-01-01',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.error', 'DOCUMENT_EXPIRED');
});

test('POST validate-expiry with null expires_on returns 200 valid:true', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');

    $response = $this->withCustomerToken($user)
        ->postJson('/v1/kyc/documents/validate-expiry', []);

    $response->assertStatus(200)
        ->assertJsonPath('data.valid', true);
});

test('POST validate-expiry with malformed date string returns 400 (EC-07)', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');

    $response = $this->withCustomerToken($user)
        ->postJson('/v1/kyc/documents/validate-expiry', [
            'expires_on' => 'not-a-date',
        ]);

    $response->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_EXPIRY_DATE');
});

test('validate-expiry calls create zero kyc_documents rows', function () {
    $user  = $this->makeCustomerWithPermission('kyc.case.update');
    $count = DB::connection('kyc')->table('kyc.kyc_documents')->count();

    $this->withCustomerToken($user)
        ->postJson('/v1/kyc/documents/validate-expiry', [
            'expires_on' => now()->addYears(1)->format('Y-m-d'),
        ]);

    expect(DB::connection('kyc')->table('kyc.kyc_documents')->count())->toBe($count);
});
