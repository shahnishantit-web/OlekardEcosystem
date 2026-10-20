<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T112 ————————————————————————————————————————————————

test('2 sequential approve calls: first succeeds, second gets 422 INVALID_CASE_STATUS', function () {
    $admin1 = $this->makeAdminWithPermission('kyc.case.approve');
    $admin2 = $this->makeAdminWithPermission('kyc.case.approve');

    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);
    $this->kycSubmitCase($case->id);

    // First approve: should succeed
    $r1 = $this->withActorToken($admin1)
        ->postJson("/v1/kyc/cases/{$case->id}/approve");
    $r1->assertStatus(200);

    // Second approve: should fail — case is no longer under_review
    $r2 = $this->withActorToken($admin2)
        ->postJson("/v1/kyc/cases/{$case->id}/approve");
    $r2->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CASE_STATUS');

    // Only one Kyc.Case.Approved outbox event must exist
    $outboxCount = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'Kyc.Case.Approved')
        ->count();

    expect($outboxCount)->toBe(1);
});
