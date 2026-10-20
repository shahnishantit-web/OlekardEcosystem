<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Enums\ProviderErrorCode;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderError;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

test('fromCode sets isTransient=true for PROVIDER_UNAVAILABLE', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::PROVIDER_UNAVAILABLE, 'HTTP_503', 'Service down');
    expect($error->isTransient)->toBeTrue();
});

test('fromCode sets isTransient=true for TIMEOUT', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::TIMEOUT, 'TIMEOUT', 'Timed out');
    expect($error->isTransient)->toBeTrue();
});

test('fromCode sets isTransient=true for RATE_LIMITED', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::RATE_LIMITED, 'HTTP_429', 'Rate limited');
    expect($error->isTransient)->toBeTrue();
});

test('fromCode sets isTransient=false for INSUFFICIENT_FUNDS', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::INSUFFICIENT_FUNDS, 'INSUF', 'Not enough');
    expect($error->isTransient)->toBeFalse();
});

test('fromCode sets isTransient=false for INVALID_REFERENCE', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::INVALID_REFERENCE, 'INVREF', 'Bad ref');
    expect($error->isTransient)->toBeFalse();
});

test('fromCode sets isTransient=false for INVALID_DESTINATION', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::INVALID_DESTINATION, 'INVDEST', 'Bad dest');
    expect($error->isTransient)->toBeFalse();
});

test('fromCode sets isTransient=false for CREDENTIAL_REJECTED', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::CREDENTIAL_REJECTED, 'AUTH401', 'Bad cred');
    expect($error->isTransient)->toBeFalse();
});

test('fromCode sets isTransient=false for CONTENT_REJECTED', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::CONTENT_REJECTED, 'POLICY', 'Bad content');
    expect($error->isTransient)->toBeFalse();
});

test('fromCode sets isTransient=false for UNKNOWN', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::UNKNOWN, 'ERR999', 'Unknown error');
    expect($error->isTransient)->toBeFalse();
});

test('empty message throws InvalidArgumentException', function () {
    expect(fn () => ProviderError::fromCode(ProviderErrorCode::TIMEOUT, 'T', ''))
        ->toThrow(\InvalidArgumentException::class);
});

test('providerSpecificCode is preserved verbatim', function () {
    $rawCode = 'ERR_CODE_12345_VENDOR_SPECIFIC';
    $error = ProviderError::fromCode(ProviderErrorCode::UNKNOWN, $rawCode, 'msg');
    expect($error->providerSpecificCode)->toBe($rawCode);
});

test('normalizedCode matches the given enum case', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::RATE_LIMITED, 'RL', 'Rate limited');
    expect($error->normalizedCode)->toBe(ProviderErrorCode::RATE_LIMITED);
});
