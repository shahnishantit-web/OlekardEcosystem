<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T114 ————————————————————————————————————————————————

test('identity.permissions has exactly 6 KYC permission rows (no duplicates from re-migration)', function () {
    $count = DB::connection('identity')
        ->table('identity.permissions')
        ->where('module', 'kyc')
        ->count();

    // Exactly 6 KYC permissions: create, update, read, review, approve, manage
    expect($count)->toBe(6);
});

test('kyc permissions have correct slugs', function () {
    $slugs = DB::connection('identity')
        ->table('identity.permissions')
        ->where('module', 'kyc')
        ->pluck('slug')
        ->sort()
        ->values()
        ->toArray();

    expect($slugs)->toBe([
        'kyc.case.approve',
        'kyc.case.create',
        'kyc.case.manage',
        'kyc.case.read',
        'kyc.case.review',
        'kyc.case.update',
    ]);
});

test('admin role has all 6 kyc permissions assigned in role_permissions', function () {
    $adminRole = DB::connection('identity')
        ->table('identity.roles')
        ->where('slug', 'admin')
        ->first(['id']);

    expect($adminRole)->not->toBeNull();

    $kycPermissionIds = DB::connection('identity')
        ->table('identity.permissions')
        ->where('module', 'kyc')
        ->pluck('id')
        ->toArray();

    expect($kycPermissionIds)->toHaveCount(6);

    $assignedCount = DB::connection('identity')
        ->table('identity.role_permissions')
        ->where('role_id', $adminRole->id)
        ->whereIn('permission_id', $kycPermissionIds)
        ->count();

    expect($assignedCount)->toBe(6);
});

test('customer role has the 3 self-service kyc permissions assigned when role exists', function () {
    $customerRole = DB::connection('identity')
        ->table('identity.roles')
        ->where('slug', 'customer')
        ->first(['id']);

    if ($customerRole === null) {
        // Customer role not seeded in this environment — skip
        expect(true)->toBeTrue();
        return;
    }

    $selfServiceIds = DB::connection('identity')
        ->table('identity.permissions')
        ->whereIn('slug', ['kyc.case.create', 'kyc.case.update', 'kyc.case.read'])
        ->pluck('id')
        ->toArray();

    expect($selfServiceIds)->toHaveCount(3);

    $assignedCount = DB::connection('identity')
        ->table('identity.role_permissions')
        ->where('role_id', $customerRole->id)
        ->whereIn('permission_id', $selfServiceIds)
        ->count();

    expect($assignedCount)->toBe(3);
});

test('agent role has the 3 self-service kyc permissions assigned when role exists', function () {
    $agentRole = DB::connection('identity')
        ->table('identity.roles')
        ->where('slug', 'agent')
        ->first(['id']);

    if ($agentRole === null) {
        // Agent role not seeded in this environment — skip
        expect(true)->toBeTrue();
        return;
    }

    $selfServiceIds = DB::connection('identity')
        ->table('identity.permissions')
        ->whereIn('slug', ['kyc.case.create', 'kyc.case.update', 'kyc.case.read'])
        ->pluck('id')
        ->toArray();

    expect($selfServiceIds)->toHaveCount(3);

    $assignedCount = DB::connection('identity')
        ->table('identity.role_permissions')
        ->where('role_id', $agentRole->id)
        ->whereIn('permission_id', $selfServiceIds)
        ->count();

    expect($assignedCount)->toBe(3);
});
