<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Enums\ProviderErrorCode;
use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderError;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderResponse;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

test('::success() sets status=SUCCESS and error=null', function () {
    $resp = ProviderResponse::success('ref-abc', '200', 'corr-1');
    expect($resp->status)->toBe(ProviderStatus::SUCCESS);
    expect($resp->error)->toBeNull();
    expect($resp->providerReference)->toBe('ref-abc');
});

test('::failure() sets status=FAILURE and carries the ProviderError', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::TIMEOUT, 'TIMEOUT', 'Timed out');
    $resp = ProviderResponse::failure($error, 'TIMEOUT', 'corr-1');
    expect($resp->status)->toBe(ProviderStatus::FAILURE);
    expect($resp->error)->toBe($error);
    expect($resp->providerReference)->toBeNull();
});

test('empty correlationId throws InvalidArgumentException on ::success()', function () {
    expect(fn () => ProviderResponse::success('ref', '200', ''))
        ->toThrow(\InvalidArgumentException::class);
});

test('empty correlationId throws InvalidArgumentException on ::failure()', function () {
    $error = ProviderError::fromCode(ProviderErrorCode::UNKNOWN, 'E', 'msg');
    expect(fn () => ProviderResponse::failure($error, 'E', ''))
        ->toThrow(\InvalidArgumentException::class);
});

test('rawProviderStatusCode is preserved in success response', function () {
    $resp = ProviderResponse::success('ref', 'CUSTOM_CODE_999', 'corr-1');
    expect($resp->rawProviderStatusCode)->toBe('CUSTOM_CODE_999');
});
