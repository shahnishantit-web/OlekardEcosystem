<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T097 ————————————————————————————————————————————————

test('kyc:detect-expired transitions past-expires_at approved cases to expired', function () {
    // Ensure validity_period_days config is NOT TBD so expiry detection runs
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('key', 'kyc.validity_period_days')
        ->update(['value' => '365', 'is_tbd' => false]);

    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    // Set case to approved with expires_at in the past
    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update([
            'status'     => 'approved',
            'verified_at' => now()->subDays(400),
            'expires_at'  => now()->subDays(35),
            'updated_at'  => now(),
        ]);

    Artisan::call('kyc:detect-expired');

    $status = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->value('status');

    expect($status)->toBe('expired');
});

test('kyc:detect-expired emits kyc.case.expired audit event', function () {
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('key', 'kyc.validity_period_days')
        ->update(['value' => '365', 'is_tbd' => false]);

    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update([
            'status'     => 'approved',
            'verified_at' => now()->subDays(400),
            'expires_at'  => now()->subDays(35),
            'updated_at'  => now(),
        ]);

    Artisan::call('kyc:detect-expired');

    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.expired')
        ->where('resource_id', $case->id)
        ->first();

    expect($audit)->not->toBeNull();
});

test('kyc:detect-expired emits Kyc.Case.Expired outbox event', function () {
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('key', 'kyc.validity_period_days')
        ->update(['value' => '365', 'is_tbd' => false]);

    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update([
            'status'     => 'approved',
            'verified_at' => now()->subDays(400),
            'expires_at'  => now()->subDays(35),
            'updated_at'  => now(),
        ]);

    Artisan::call('kyc:detect-expired');

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'Kyc.Case.Expired')
        ->first();

    expect($outbox)->not->toBeNull();
});

test('kyc:detect-expired does not touch cases with future expires_at', function () {
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('key', 'kyc.validity_period_days')
        ->update(['value' => '365', 'is_tbd' => false]);

    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update([
            'status'     => 'approved',
            'verified_at' => now()->subDays(30),
            'expires_at'  => now()->addDays(335), // still valid
            'updated_at'  => now(),
        ]);

    Artisan::call('kyc:detect-expired');

    $status = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->value('status');

    expect($status)->toBe('approved');
});

test('kyc:detect-expired with --dry-run makes zero DB changes', function () {
    DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('key', 'kyc.validity_period_days')
        ->update(['value' => '365', 'is_tbd' => false]);

    $customer = $this->makeCustomerWithPermission('kyc.case.create');
    $case     = $this->kycCreateCase($customer->id);

    DB::connection('kyc')->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->update([
            'status'     => 'approved',
            'verified_at' => now()->subDays(400),
            'expires_at'  => now()->subDays(35),
            'updated_at'  => now(),
        ]);

    Artisan::call('kyc:detect-expired', ['--dry-run' => true]);

    $status = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->value('status');

    expect($status)->toBe('approved');
});
