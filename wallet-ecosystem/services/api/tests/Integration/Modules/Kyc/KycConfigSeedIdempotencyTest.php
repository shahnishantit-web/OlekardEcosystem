<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T115 ————————————————————————————————————————————————

test('platform.configuration_values has exactly 6 kyc.* rows', function () {
    $count = DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('key', 'like', 'kyc.%')
        ->count();

    expect($count)->toBe(6);
});

test('4 kyc configuration entries are marked is_tbd=true', function () {
    $tbdCount = DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('key', 'like', 'kyc.%')
        ->where('is_tbd', true)
        ->count();

    expect($tbdCount)->toBe(4);
});

test('kyc.auto_verify_enabled is seeded as false', function () {
    $value = DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('key', 'kyc.auto_verify_enabled')
        ->first();

    expect($value)->not->toBeNull();
    expect($value->is_tbd)->toBeFalsy();
    expect($value->value)->toBe('false');
});

test('kyc.provider_max_retries is seeded as 3', function () {
    $value = DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('key', 'kyc.provider_max_retries')
        ->first();

    expect($value)->not->toBeNull();
    expect((int) $value->value)->toBe(3);
});
