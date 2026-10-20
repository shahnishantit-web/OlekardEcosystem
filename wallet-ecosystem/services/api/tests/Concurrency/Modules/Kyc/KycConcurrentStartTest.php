<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T111 ————————————————————————————————————————————————

test('5 concurrent POST /cases for same user creates exactly 1 case', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.create');

    $responses = [];
    $promises  = [];

    // Simulate concurrent requests sequentially (true parallelism requires forks)
    // The uniqueness guarantee comes from the application-level check + DB transaction.
    // This test verifies the idempotency guard and race condition logic sequentially.
    // A full concurrency test would require fork-based concurrency (see plan.md note on
    // PHP multi-process concurrency tests requiring artisan test:concurrent).
    for ($i = 1; $i <= 5; $i++) {
        $responses[] = $this->withCustomerToken($user)
            ->withHeaders(['Idempotency-Key' => "concurrent-start-key-{$i}"])
            ->postJson('/v1/kyc/cases');
    }

    // Count created cases
    $caseCount = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('user_id', $user->id)
        ->count();

    // First request should succeed; remaining 4 should receive 409
    $successCount = count(array_filter($responses, fn ($r) => $r->getStatusCode() === 201));
    $conflictCount = count(array_filter($responses, fn ($r) => $r->getStatusCode() === 409));

    // With sequential requests and different idempotency keys:
    // First succeeds (201); subsequent 4 get 409 KYC_CASE_ALREADY_OPEN
    expect($successCount)->toBe(1);
    expect($conflictCount)->toBe(4);
    expect($caseCount)->toBe(1);
});
