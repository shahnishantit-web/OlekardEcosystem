<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T090 ————————————————————————————————————————————————

test('GET /cases/my returns own case with rejection_reason', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.read');
    $case     = $this->kycCreateCase($customer->id);
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update([
            'status'           => 'rejected',
            'rejection_reason' => 'Documents blurry.',
            'updated_at'       => now(),
        ]);

    $response = $this->withCustomerToken($customer)
        ->getJson('/v1/kyc/cases/my');

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Documents blurry.');
});

test('GET /cases/my returns 404 when customer has no KYC case', function () {
    $customer = $this->makeCustomerWithPermission('kyc.case.read');

    $response = $this->withCustomerToken($customer)
        ->getJson('/v1/kyc/cases/my');

    $response->assertStatus(404);
});

test('GET /cases/my customer cannot pass ?user_id= for another user (sees own case)', function () {
    $user1 = $this->makeCustomerWithPermission('kyc.case.read');
    $user2 = $this->makeCustomerWithPermission('kyc.case.read');
    $case1 = $this->kycCreateCase($user1->id);
    $this->kycCreateCase($user2->id);

    // user2 tries to view user1's case via ?user_id= — should see own case, not user1's
    $response = $this->withCustomerToken($user2)
        ->getJson("/v1/kyc/cases/my?user_id={$user1->id}");

    $response->assertStatus(200);
    // Should be user2's case (or 404 if that logic routes back to own)
    // The case_id in response must NOT be user1's case
    expect($response->json('data.case_id'))->not->toBe($case1->id);
});

test('GET /cases/my compliance officer can pass ?user_id= to see any case', function () {
    $admin    = $this->makeAdminWithPermission('kyc.case.read', 'kyc.case.review');
    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    $response = $this->withActorToken($admin)
        ->getJson("/v1/kyc/cases/my?user_id={$customer->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.case_id', $case->id);
});
