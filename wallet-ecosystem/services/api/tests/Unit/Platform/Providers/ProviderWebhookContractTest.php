<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract;
use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use App\Modules\Platform\Domain\ValueObjects\Providers\NormalizedWebhookEvent;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

// ---------------------------------------------------------------------------
// Inline test implementation — validates the contract's parse() obligations.
// Signature validity is controlled via constructor parameter.
// The implementation extracts correlationId from headers (X-Correlation-ID),
// falling back to a deterministic hash when absent.
// ---------------------------------------------------------------------------
final class VerifyingWebhookAdapter implements ProviderWebhookContract
{
    public function __construct(
        private readonly bool    $signatureValid,
        private readonly ?string $platformEntityId,
    ) {}

    public function parse(
        string $rawPayload,
        string $signature,
        array  $headers,
    ): NormalizedWebhookEvent {
        // Obligation 1: validate signature first
        if (! $this->signatureValid) {
            throw new WebhookSignatureException('Signature validation failed.');
        }

        $correlationId = $headers['x-correlation-id'] ?? ('gen-' . md5($rawPayload));
        $idempotencyKey = $headers['x-delivery-id'] ?? ('idem-' . md5($rawPayload . 'test_provider'));

        // Obligation 3: null platformEntityId (not throw) when unresolvable
        if ($this->platformEntityId !== null) {
            return NormalizedWebhookEvent::resolved(
                idempotencyKey:      $idempotencyKey,
                correlationId:       $correlationId,
                platformEntityId:    $this->platformEntityId,
                platformEntityType:  'transaction',
                providerKey:         'test_provider',
                providerReference:   $headers['x-provider-ref'] ?? null,
                normalizedEventType: 'payment.success',
                status:              ProviderStatus::SUCCESS,
                receivedAt:          new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                rawPayload:          $rawPayload, // Obligation 4: preserve verbatim
            );
        }

        return NormalizedWebhookEvent::unresolved(
            idempotencyKey:      $idempotencyKey,
            correlationId:       $correlationId,
            providerKey:         'test_provider',
            providerReference:   $headers['x-provider-ref'] ?? null,
            normalizedEventType: 'payment.success',
            status:              ProviderStatus::SUCCESS,
            receivedAt:          new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            rawPayload:          $rawPayload, // Obligation 4: preserve verbatim
        );
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('parse() returns NormalizedWebhookEvent for a valid signature', function () {
    $adapter = new VerifyingWebhookAdapter(signatureValid: true, platformEntityId: 'entity-001');

    $event = $adapter->parse(
        rawPayload: '{"status":"success"}',
        signature:  'sha256=abc123',
        headers:    ['x-delivery-id' => 'del-001', 'x-correlation-id' => 'corr-001'],
    );

    expect($event)->toBeInstanceOf(NormalizedWebhookEvent::class);
    expect($event->idempotencyKey)->toBe('del-001');
    expect($event->correlationId)->toBe('corr-001');
    expect($event->isResolved())->toBeTrue();
    expect($event->platformEntityId)->toBe('entity-001');
});

test('parse() throws WebhookSignatureException for an invalid signature', function () {
    $adapter = new VerifyingWebhookAdapter(signatureValid: false, platformEntityId: null);

    expect(fn () => $adapter->parse(
        rawPayload: '{"status":"success"}',
        signature:  'sha256=invalid',
        headers:    [],
    ))->toThrow(WebhookSignatureException::class);
});

test('parse() sets platformEntityId to null when entity is unresolvable — does not throw', function () {
    $adapter = new VerifyingWebhookAdapter(signatureValid: true, platformEntityId: null);

    $event = $adapter->parse(
        rawPayload: '{"status":"unknown"}',
        signature:  'sha256=abc123',
        headers:    ['x-delivery-id' => 'del-002'],
    );

    expect($event->platformEntityId)->toBeNull();
    expect($event->isResolved())->toBeFalse();
});

test('parse() preserves rawPayload verbatim for auditability', function () {
    $adapter  = new VerifyingWebhookAdapter(signatureValid: true, platformEntityId: null);
    $payload  = '{"status":"success","amount":1000}';

    $event = $adapter->parse(
        rawPayload: $payload,
        signature:  'sha256=abc123',
        headers:    ['x-delivery-id' => 'del-003'],
    );

    expect($event->rawPayload)->toBe($payload);
});

test('ProviderWebhookContract is an interface — injection is possible', function () {
    $reflection = new \ReflectionClass(ProviderWebhookContract::class);
    expect($reflection->isInterface())->toBeTrue();
});

test('parse() signature has $signature as the second positional parameter per contracts doc', function () {
    $reflection = new \ReflectionClass(VerifyingWebhookAdapter::class);
    $method     = $reflection->getMethod('parse');
    $params     = $method->getParameters();

    expect($params[0]->getName())->toBe('rawPayload');
    expect($params[1]->getName())->toBe('signature');
    expect($params[2]->getName())->toBe('headers');
    expect($params[1]->getType()?->getName())->toBe('string');
    expect($params[2]->getType()?->getName())->toBe('array');
});
