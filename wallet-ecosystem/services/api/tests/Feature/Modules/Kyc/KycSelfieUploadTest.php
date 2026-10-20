<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T093 ————————————————————————————————————————————————

test('upload selfie returns 201', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $file = UploadedFile::fake()->image('selfie.jpg')->size(100);

    $response = $this->withCustomerToken($user)
        ->postJson("/v1/kyc/cases/{$case->id}/documents", [
            'document_type' => 'selfie',
            'file'          => $file,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.document_type', 'selfie');
});

test('second selfie upload soft-deletes first and creates new active row', function () {
    $user  = $this->makeCustomerWithPermission('kyc.case.update');
    $case  = $this->kycCreateCase($user->id);
    $file1 = UploadedFile::fake()->image('selfie1.jpg')->size(100);
    $file2 = UploadedFile::fake()->image('selfie2.jpg')->size(100);

    $r1 = $this->withCustomerToken($user)
        ->postJson("/v1/kyc/cases/{$case->id}/documents", [
            'document_type' => 'selfie',
            'file'          => $file1,
        ]);
    $r1->assertStatus(201);

    $r2 = $this->withCustomerToken($user)
        ->postJson("/v1/kyc/cases/{$case->id}/documents", [
            'document_type' => 'selfie',
            'file'          => $file2,
        ]);
    $r2->assertStatus(201);

    $activeCount = DB::connection('kyc')
        ->table('kyc.kyc_documents')
        ->where('kyc_case_id', $case->id)
        ->where('document_type', 'selfie')
        ->whereNull('deleted_at')
        ->count();

    $softDeletedCount = DB::connection('kyc')
        ->table('kyc.kyc_documents')
        ->where('kyc_case_id', $case->id)
        ->where('document_type', 'selfie')
        ->whereNotNull('deleted_at')
        ->count();

    expect($activeCount)->toBe(1);
    expect($softDeletedCount)->toBe(1);

    // doc IDs must be different
    expect($r1->json('data.document_id'))->not->toBe($r2->json('data.document_id'));
});

test('after two selfie uploads only 1 active selfie row in database', function () {
    $user  = $this->makeCustomerWithPermission('kyc.case.update');
    $case  = $this->kycCreateCase($user->id);

    for ($i = 0; $i < 2; $i++) {
        $this->withCustomerToken($user)
            ->postJson("/v1/kyc/cases/{$case->id}/documents", [
                'document_type' => 'selfie',
                'file'          => UploadedFile::fake()->image("selfie{$i}.jpg")->size(100),
            ])->assertStatus(201);
    }

    $activeCount = DB::connection('kyc')
        ->table('kyc.kyc_documents')
        ->where('kyc_case_id', $case->id)
        ->where('document_type', 'selfie')
        ->whereNull('deleted_at')
        ->count();

    expect($activeCount)->toBe(1);
});
