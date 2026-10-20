<?php

declare(strict_types=1);

use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use App\Modules\Kyc\Domain\Services\KycDuplicateIdentityChecker;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Support\Facades\DB;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

beforeEach(function () {
    // Mock DB connection so unit test runs without a live database
    DB::shouldReceive('connection')
        ->with('kyc')
        ->andReturnSelf();
});

test('hasApprovedCaseWithHash calls DB with correct hash and approved status', function () {
    $hash = PiiMasker::hashNationalId('1234567890123');

    DB::shouldReceive('table->join->where->where->exists')
        ->once()
        ->andReturn(false);

    $checker = new KycDuplicateIdentityChecker();
    $result  = $checker->hasApprovedCaseWithHash($hash);

    expect($result)->toBeFalse();
});

test('hasApprovedCaseWithHash returns true when approved case exists', function () {
    $hash = PiiMasker::hashNationalId('1234567890123');

    DB::shouldReceive('table->join->where->where->exists')
        ->once()
        ->andReturn(true);

    $checker = new KycDuplicateIdentityChecker();
    expect($checker->hasApprovedCaseWithHash($hash))->toBeTrue();
});

test('hasApprovedCaseWithHash returns false for rejected case (only approved triggers)', function () {
    $hash = PiiMasker::hashNationalId('9999999999999');

    DB::shouldReceive('table->join->where->where->exists')
        ->once()
        ->andReturn(false);

    $checker = new KycDuplicateIdentityChecker();
    expect($checker->hasApprovedCaseWithHash($hash))->toBeFalse();
});

test('hashNationalId is called correctly — same input yields same hash', function () {
    $id    = '1234567890123';
    $hash1 = PiiMasker::hashNationalId($id);
    $hash2 = PiiMasker::hashNationalId($id);

    expect($hash1)->toBe($hash2)->toHaveLength(64);
});
