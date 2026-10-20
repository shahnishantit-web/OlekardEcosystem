<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
use App\Modules\Platform\Domain\Enums\ProviderErrorCode;
use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderError;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;
use App\Modules\Platform\Infrastructure\Providers\NullProviderAdapter;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

test('::alwaysSucceed() returns SUCCESS response on send()', function () {
    $caps = ProviderCapabilitySet::of(ProviderCapabilityEnum::InitiatePayment);
    $adapter = NullProviderAdapter::alwaysSucceed($caps);

    $req = ProviderRequest::forNonFinancialOperation('e1', 'tx', 'initiate_payment', 'corr-1');
    $resp = $adapter->send($req);

    expect($resp->status)->toBe(ProviderStatus::SUCCESS);
});

test('::alwaysFail() returns FAILURE response on send()', function () {
    $caps = ProviderCapabilitySet::of(ProviderCapabilityEnum::InitiatePayment);
    $error = ProviderError::fromCode(ProviderErrorCode::PROVIDER_UNAVAILABLE, 'HTTP_503', 'Down');
    $adapter = NullProviderAdapter::alwaysFail($error, $caps);

    $req = ProviderRequest::forNonFinancialOperation('e1', 'tx', 'initiate_payment', 'corr-1');
    $resp = $adapter->send($req);

    expect($resp->status)->toBe(ProviderStatus::FAILURE);
    expect($resp->error->normalizedCode)->toBe(ProviderErrorCode::PROVIDER_UNAVAILABLE);
});

test('getProviderIdentifier() returns "null" by default', function () {
    $adapter = NullProviderAdapter::alwaysSucceed(ProviderCapabilitySet::empty());
    expect($adapter->getProviderIdentifier())->toBe('null');
});

test('getCapabilities() returns the configured capability set', function () {
    $caps = ProviderCapabilitySet::of(ProviderCapabilityEnum::DeliverOtp, ProviderCapabilityEnum::ScoreRisk);
    $adapter = NullProviderAdapter::alwaysSucceed($caps);
    expect($adapter->getCapabilities()->count())->toBe(2);
    expect($adapter->getCapabilities()->has(ProviderCapabilityEnum::DeliverOtp))->toBeTrue();
});

test('getHealthStatus() returns UP', function () {
    $adapter = NullProviderAdapter::alwaysSucceed(ProviderCapabilitySet::empty());
    $health = $adapter->getHealthStatus();
    expect($health->status)->toBe(\App\Modules\Platform\Domain\Enums\ProviderStatus::UP);
});

test('send() does not make any HTTP call (no Http client dependency injected)', function () {
    $adapter = NullProviderAdapter::alwaysSucceed(ProviderCapabilitySet::empty());
    $req = ProviderRequest::forNonFinancialOperation('e1', 'tx', 'query_balance', 'corr-1');

    // If NullProviderAdapter attempted an HTTP call it would fail since no HTTP client is configured.
    // The fact that it returns a response proves no network call was made.
    $resp = $adapter->send($req);
    expect($resp)->not->toBeNull();
});
