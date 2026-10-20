<?php

declare(strict_types=1);

use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

test('hashNationalId returns 64-char lowercase hex string', function () {
    $hash = PiiMasker::hashNationalId('1234567890123');

    expect($hash)
        ->toBeString()
        ->toHaveLength(64)
        ->toMatch('/^[0-9a-f]{64}$/');
});

test('hashNationalId is deterministic — same input always yields same hash', function () {
    $id = '9876543210987';

    expect(PiiMasker::hashNationalId($id))->toBe(PiiMasker::hashNationalId($id));
});

test('hashNationalId produces different hashes for different inputs', function () {
    $hashA = PiiMasker::hashNationalId('1234567890123');
    $hashB = PiiMasker::hashNationalId('9876543210987');

    expect($hashA)->not->toBe($hashB);
});

test('hashNationalId returns empty string for empty input', function () {
    expect(PiiMasker::hashNationalId(''))->toBe('');
});

test('hashNationalId uses HMAC — differs from plain sha256', function () {
    $id   = '1234567890123';
    $hmac = PiiMasker::hashNationalId($id);
    $plain = hash('sha256', $id);

    expect($hmac)->not->toBe($plain);
});
