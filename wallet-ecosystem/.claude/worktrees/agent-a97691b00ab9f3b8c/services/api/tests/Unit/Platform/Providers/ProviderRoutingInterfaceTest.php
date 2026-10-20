<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderHealthInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderRoutingInterface;
use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\Exceptions\ProviderException;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderHealthStatus;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;
use App\Modules\Platform\Infrastructure\Providers\NullProviderAdapter;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

// ---------------------------------------------------------------------------
// Inline ordered routing strategy — selects the first eligible candidate.
// Eligibility: capability matches operationType AND health is not DOWN.
// ---------------------------------------------------------------------------
final class OrderedRoutingStrategy implements ProviderRoutingInterface
{
    public function selectProvider(ProviderRequest $context, array $candidates): ProviderAdapterInterface
    {
        foreach ($candidates as $adapter) {
            // Capability check: operationType must map to a declared capability
            $capEnum = ProviderCapabilityEnum::tryFrom($context->operationType);
            if ($capEnum !== null && ! $adapter->getCapabilities()->has($capEnum)) {
                continue;
            }
            // Health check: skip DOWN providers (only when health is declared)
            if ($adapter instanceof ProviderHealthInterface) {
                if ($adapter->getHealthStatus()->status === ProviderStatus::DOWN) {
                    continue;
                }
            }
            return $adapter;
        }
        throw new ProviderException('No eligible provider found in the candidate set.');
    }
}

// ---------------------------------------------------------------------------
// Helper: inline adapter that is always DOWN
// ---------------------------------------------------------------------------
final class AlwaysDownAdapter implements ProviderAdapterInterface, ProviderHealthInterface
{
    public function __construct(private readonly ProviderCapabilitySet $capabilities) {}

    public function send(ProviderRequest $request): \App\Modules\Platform\Domain\ValueObjects\Providers\ProviderResponse
    {
        throw new ProviderException('Provider is DOWN — send() must never be called on a DOWN adapter.');
    }

    public function getCapabilities(): ProviderCapabilitySet
    {
        return $this->capabilities;
    }

    public function getProviderIdentifier(): string
    {
        return 'always_down';
    }

    public function getHealthStatus(): ProviderHealthStatus
    {
        return ProviderHealthStatus::down('Maintenance', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('selectProvider() returns the first eligible adapter from the candidate list', function () {
    $strategy = new OrderedRoutingStrategy();

    $adapter = NullProviderAdapter::alwaysSucceed(
        ProviderCapabilitySet::of(ProviderCapabilityEnum::InitiatePayment)
    );

    $request = ProviderRequest::forNonFinancialOperation(
        entityId:      'eid-001',
        entityType:    'transaction',
        opType:        'initiate_payment',
        correlationId: 'corr-001',
    );

    $selected = $strategy->selectProvider($request, [$adapter]);

    expect($selected)->toBe($adapter);
});

test('selectProvider() throws ProviderException when candidates list is empty', function () {
    $strategy = new OrderedRoutingStrategy();

    $request = ProviderRequest::forNonFinancialOperation(
        entityId:      'eid-002',
        entityType:    'transaction',
        opType:        'initiate_payment',
        correlationId: 'corr-002',
    );

    expect(fn () => $strategy->selectProvider($request, []))
        ->toThrow(ProviderException::class);
});

test('ProviderRoutingInterface is an interface — not an abstract class', function () {
    $reflection = new \ReflectionClass(ProviderRoutingInterface::class);
    expect($reflection->isInterface())->toBeTrue();
});

test('selectProvider() skips adapter whose capability set lacks the required operation type', function () {
    $strategy = new OrderedRoutingStrategy();

    // First adapter supports DeliverOtp only — wrong capability for InitiatePayment
    $wrong = NullProviderAdapter::alwaysSucceed(
        ProviderCapabilitySet::of(ProviderCapabilityEnum::DeliverOtp)
    );
    // Second adapter supports InitiatePayment — correct
    $correct = NullProviderAdapter::alwaysSucceed(
        ProviderCapabilitySet::of(ProviderCapabilityEnum::InitiatePayment)
    );

    $request = ProviderRequest::forNonFinancialOperation(
        entityId:      'eid-003',
        entityType:    'transaction',
        opType:        ProviderCapabilityEnum::InitiatePayment->value,
        correlationId: 'corr-003',
    );

    $selected = $strategy->selectProvider($request, [$wrong, $correct]);

    expect($selected)->toBe($correct);
});

test('selectProvider() skips adapter with ProviderHealthStatus::DOWN and returns the UP adapter', function () {
    $strategy = new OrderedRoutingStrategy();

    $caps    = ProviderCapabilitySet::of(ProviderCapabilityEnum::InitiatePayment);
    $down    = new AlwaysDownAdapter($caps);
    $healthy = NullProviderAdapter::alwaysSucceed($caps);

    $request = ProviderRequest::forNonFinancialOperation(
        entityId:      'eid-004',
        entityType:    'transaction',
        opType:        ProviderCapabilityEnum::InitiatePayment->value,
        correlationId: 'corr-004',
    );

    $selected = $strategy->selectProvider($request, [$down, $healthy]);

    expect($selected)->toBe($healthy);
});

test('selectProvider() returns a ProviderAdapterInterface instance', function () {
    $strategy = new OrderedRoutingStrategy();

    $adapter = NullProviderAdapter::alwaysSucceed(
        ProviderCapabilitySet::of(ProviderCapabilityEnum::QueryBalance)
    );

    $request = ProviderRequest::forNonFinancialOperation(
        entityId:      'eid-005',
        entityType:    'kyc_check',
        opType:        ProviderCapabilityEnum::QueryBalance->value,
        correlationId: 'corr-005',
    );

    $result = $strategy->selectProvider($request, [$adapter]);

    expect($result)->toBeInstanceOf(ProviderAdapterInterface::class);
});
