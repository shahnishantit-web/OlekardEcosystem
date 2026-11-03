<?php

declare(strict_types=1);

use App\Modules\Events\Application\AdmissionCredentialVault;
use Tests\TestCase;

uses(TestCase::class);

it('creates unique opaque credentials with a lookup hash and recoverable encrypted display value', function (): void {
    $vault = new AdmissionCredentialVault;
    $first = $vault->create();
    $second = $vault->create();

    expect($first['plaintext'])->toMatch('/\A[A-Za-z0-9_-]{43}\z/')
        ->and($first['plaintext'])->not->toBe($second['plaintext'])
        ->and($first['hash'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($first['encrypted'])->not->toBe($first['plaintext'])
        ->and($vault->matches($first['plaintext'], $first['hash']))->toBeTrue()
        ->and($vault->matches($second['plaintext'], $first['hash']))->toBeFalse()
        ->and($vault->recoverForDisplay($first['encrypted']))->toBe($first['plaintext']);
});

it('rejects malformed scanner credentials and hashes', function (): void {
    $vault = new AdmissionCredentialVault;

    expect($vault->matches('not-a-ticket', str_repeat('a', 64)))->toBeFalse()
        ->and($vault->matches(str_repeat('a', 43), 'not-a-hash'))->toBeFalse();
});
