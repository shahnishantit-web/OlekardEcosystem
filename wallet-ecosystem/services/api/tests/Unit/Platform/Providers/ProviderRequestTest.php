<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\ValueObjects\Currency;
use App\Modules\Platform\Domain\ValueObjects\Money;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

test('::forFinancialOperation() with zero amount throws InvalidArgumentException', function () {
    $currency = Currency::of('BWP');
    $amount = Money::of(0, $currency);
    expect(fn () => ProviderRequest::forFinancialOperation(
        $amount, $currency, 'entity-1', 'transaction', 'initiate_payment', 'corr-1'
    ))->toThrow(\InvalidArgumentException::class);
});

test('metadata blocklist rejects api_key key', function () {
    expect(fn () => ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1', ['api_key' => 'secret-value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('metadata blocklist rejects secret key', function () {
    expect(fn () => ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1', ['secret' => 'value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('metadata blocklist rejects password key', function () {
    expect(fn () => ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1', ['password' => 'value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('metadata blocklist rejects token key', function () {
    expect(fn () => ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1', ['token' => 'bearer-value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('non-financial constructor sets amount and currency to null', function () {
    $req = ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1'
    );
    expect($req->amount)->toBeNull();
    expect($req->currency)->toBeNull();
});

test('empty correlationId on forFinancialOperation throws InvalidArgumentException', function () {
    $currency = Currency::of('BWP');
    $amount = Money::of(100, $currency);
    expect(fn () => ProviderRequest::forFinancialOperation(
        $amount, $currency, 'entity-1', 'transaction', 'initiate_payment', ''
    ))->toThrow(\InvalidArgumentException::class);
});

test('empty correlationId on forNonFinancialOperation throws InvalidArgumentException', function () {
    expect(fn () => ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', ''
    ))->toThrow(\InvalidArgumentException::class);
});

test('valid financial request is created with correct fields', function () {
    $currency = Currency::of('BWP');
    $amount = Money::of(5000, $currency);
    $req = ProviderRequest::forFinancialOperation(
        $amount, $currency, 'entity-id-1', 'transaction', 'initiate_payment', 'corr-abc',
        ['reference' => 'ref-123']
    );
    expect($req->platformEntityId)->toBe('entity-id-1');
    expect($req->operationType)->toBe('initiate_payment');
    expect($req->correlationId)->toBe('corr-abc');
    expect($req->metadata)->toBe(['reference' => 'ref-123']);
});
