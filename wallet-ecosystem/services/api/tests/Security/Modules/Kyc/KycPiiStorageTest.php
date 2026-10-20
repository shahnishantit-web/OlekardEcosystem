<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T102 ————————————————————————————————————————————————

test('kyc_personal_info has national_id_hash column and no national_id_number column', function () {
    $columns = DB::connection('kyc')
        ->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'kyc' AND table_name = 'kyc_personal_info'");

    $columnNames = array_map(fn ($c) => $c->column_name, $columns);

    expect($columnNames)->toContain('national_id_hash');
    expect($columnNames)->not->toContain('national_id_number');
});

test('captured national_id_hash is 64-char hex string', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);

    $this->withCustomerToken($user)->putJson("/v1/kyc/cases/{$case->id}/personal-information", [
        'full_legal_name'    => 'PII Test User',
        'date_of_birth'      => '1988-07-12',
        'nationality'        => 'ZA',
        'national_id_type'   => 'passport',
        'national_id_number' => 'ZA987654321',
        'address' => [
            'line_1'       => '1 Test Road',
            'city'         => 'Cape Town',
            'postal_code'  => '8001',
            'country_code' => 'ZA',
        ],
    ])->assertStatus(200);

    $row = DB::connection('kyc')
        ->table('kyc.kyc_personal_info')
        ->where('kyc_case_id', $case->id)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->national_id_hash)->toHaveLength(64);
    expect(ctype_xdigit($row->national_id_hash))->toBeTrue();

    // Plaintext national ID must not appear anywhere in audit
    $auditPayload = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.personal-information-captured')
        ->where('resource_id', $case->id)
        ->value('metadata');

    expect((string) $auditPayload)->not->toContain('ZA987654321');
});

test('audit events for kyc actions store ip_hash as 64-char hex, not plaintext IP', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.create', 'kyc.case.update');
    $case = $this->kycCreateCase($user->id);

    // Audit event written by startCase is keyed by kyc.case.initiated
    $auditRow = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.initiated')
        ->where('resource_id', $case->id)
        ->first();

    expect($auditRow)->not->toBeNull();
    // ip_hash must be either null (test environment) or a 64-char hex string — never a raw dotted IP
    if ($auditRow->ip_hash !== null) {
        expect($auditRow->ip_hash)->toHaveLength(64);
        expect(ctype_xdigit($auditRow->ip_hash))->toBeTrue();
        expect($auditRow->ip_hash)->not->toMatch('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/');
    }
});
