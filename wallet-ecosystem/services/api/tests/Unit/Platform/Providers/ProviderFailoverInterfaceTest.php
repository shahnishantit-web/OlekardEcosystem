<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderFailoverInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderHealthInterface;
use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
use App\Modules\Platform\Domain\Enums\ProviderErrorCode;
use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderError;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderHealthStatus;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;
use App\Modules\Platform\Infrastructure\Providers\NullProviderAdapter;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

// ---------------------------------------------------------------------------
// Inline ordered failover strategy — selects the first non-current candidate.
// Contract obligations enforced: no self-reference, returns null on exhaustion.
// ---------------------------------------------------------------------------
final class OrderedFailoverStrategy implements ProviderFailoverInterface
{
    public function shouldFailover(ProviderError $error): bool
    {
        return $error->isTransient;
    }

    public function selectFallback(
        ProviderAdapterInterface $current,
        array $candidates,
        ProviderRequest $context,
    ): ?ProviderAdapterInterface {
        foreach ($candidates as $adapter) {
            if ($adapter === $current) {
                continue; // Never return the already-failed adapter
            }
            // Health check: skip DOWN providers
            if ($adapter instanceof ProviderHealthInterface) {
                if ($adapter->getHealthStatus()->status === ProviderStatus::DOWN) {
                    continue;
                }
            }
            return $adapter;
        }
        return null; // Chain exhausted — caller surfaces terminal failure
    }
}

// ---------------------------------------------------------------------------
// Helper: request fixture
// ---------------------------------------------------------------------------
function makeFailoverRequest(): ProviderRequest
{
    return ProviderRequest::forNonFinancialOperation(
        entityId:      'eid-failover',
        entityType:    'transaction',
        opType:        ProviderCapabilityEnum::InitiatePayment->value,
        correlationId: 'corr-failover',
    );
}

// ---------------------------------------------------------------------------
// shouldFailover — transient errors
// ---------------------------------------------------------------------------

test('shouldFailover() returns true for PROVIDER_UNAVAILABLE (transient)', function () {
    $strategy = new OrderedFailoverStrategy();
    $error    = ProviderError::fromCode(ProviderErrorCode::PROVIDER_UNAVAILABLE, 'E500', 'Service down');

    expect($strategy->shouldFailover($error))->toBeTrue();
});

test('shouldFailover() returns true for TIMEOUT (transient)', function () {
    $strategy = new OrderedFailoverStrategy();
    $error    = ProviderError::fromCode(ProviderErrorCode::TIMEOUT, 'E408', 'Request timed out');

    expect($strategy->shouldFailover($error))->toBeTrue();
});

test('shouldFailover() returns true for RATE_LIMITED (transient)', function () {
    $strategy = new OrderedFailoverStrategy();
    $error    = ProviderError::fromCode(ProviderErrorCode::RATE_LIMITED, 'E429', 'Too many requests');

    expect($strategy->shouldFailover($error))->toBeTrue();
});

// ---------------------------------------------------------------------------
// shouldFailover — permanent errors
// ---------------------------------------------------------------------------

test('shouldFailover() returns false for INSUFFICIENT_FUNDS (non-transient)', function () {
    $strategy = new OrderedFailoverStrategy();
    $error    = ProviderError::fromCode(ProviderErrorCode::INSUFFICIENT_FUNDS, 'E402', 'Balance too low');

    expect($strategy->shouldFailover($error))->toBeFalse();
});

test('shouldFailover() returns false for CREDENTIAL_REJECTED (non-transient)', function () {
    $strategy = new OrderedFailoverStrategy();
    $error    = ProviderError::fromCode(ProviderErrorCode::CREDENTIAL_REJECTED, 'E401', 'Invalid credentials');

    expect($strategy->shouldFailover($error))->toBeFalse();
});

test('shouldFailover() returns false for INVALID_DESTINATION (non-transient)', function () {
    $strategy = new OrderedFailoverStrategy();
    $error    = ProviderError::fromCode(ProviderErrorCode::INVALID_DESTINATION, 'E422', 'Invalid account');

    expect($strategy->shouldFailover($error))->toBeFalse();
});

// ---------------------------------------------------------------------------
// selectFallback
// ---------------------------------------------------------------------------

test('selectFallback() returns the first non-current adapter from the candidate list', function () {
    $strategy = new OrderedFailoverStrategy();
    $caps     = ProviderCapabilitySet::of(ProviderCapabilityEnum::InitiatePayment);

    $primary  = NullProviderAdapter::alwaysSucceed($caps);
    $fallback = NullProviderAdapter::alwaysSucceed($caps);

    $result = $strategy->selectFallback($primary, [$primary, $fallback], makeFailoverRequest());

    expect($result)->toBe($fallback);
});

test('selectFallback() excludes the current (failed) adapter from the result', function () {
    $strategy = new OrderedFailoverStrategy();
    $caps     = ProviderCapabilitySet::of(ProviderCapabilityEnum::InitiatePayment);

    $primary  = NullProviderAdapter::alwaysSucceed($caps);
    $fallback = NullProviderAdapter::alwaysSucceed($caps);

    $result = $strategy->selectFallback($primary, [$primary, $fallback], makeFailoverRequest());

    expect($result)->not->toBe($primary);
    expect($result)->toBe($fallback);
});

test('selectFallback() returns null when chain is exhausted — no valid fallback remains', function () {
    $strategy = new OrderedFailoverStrategy();
    $caps     = ProviderCapabilitySet::of(ProviderCapabilityEnum::InitiatePayment);

    $only = NullProviderAdapter::alwaysSucceed($caps);

    // Only one candidate — and it's the failed one
    $result = $strategy->selectFallback($only, [$only], makeFailoverRequest());

    expect($result)->toBeNull();
});

test('selectFallback() returns null when candidates list is empty', function () {
    $strategy = new OrderedFailoverStrategy();
    $caps     = ProviderCapabilitySet::of(ProviderCapabilityEnum::InitiatePayment);

    $primary = NullProviderAdapter::alwaysSucceed($caps);

    $result = $strategy->selectFallback($primary, [], makeFailoverRequest());

    expect($result)->toBeNull();
});

test('ProviderFailoverInterface is an interface — not an abstract class', function () {
    $reflection = new \ReflectionClass(ProviderFailoverInterface::class);
    expect($reflection->isInterface())->toBeTrue();
});
