<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\ValueObjects\Providers\NormalizedWebhookEvent;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

// ---------------------------------------------------------------------------
// resolved() factory
// ---------------------------------------------------------------------------

test('resolved() factory sets all fields and isResolved() returns true', function () use ($now) {
    $event = NormalizedWebhookEvent::resolved(
        idempotencyKey:      'idem-001',
        correlationId:       'corr-001',
        platformEntityId:    'entity-001',
        platformEntityType:  'transaction',
        providerKey:         'mno_primary',
        providerReference:   'TXN-001',
        normalizedEventType: 'payment.success',
        status:              ProviderStatus::SUCCESS,
        receivedAt:          $now,
        rawPayload:          '{"status":"success"}',
        providerTimestamp:   $now,
        metadata:            ['region' => 'BW'],
    );

    expect($event->idempotencyKey)->toBe('idem-001');
    expect($event->correlationId)->toBe('corr-001');
    expect($event->platformEntityId)->toBe('entity-001');
    expect($event->platformEntityType)->toBe('transaction');
    expect($event->providerKey)->toBe('mno_primary');
    expect($event->providerReference)->toBe('TXN-001');
    expect($event->normalizedEventType)->toBe('payment.success');
    expect($event->status)->toBe(ProviderStatus::SUCCESS);
    expect($event->receivedAt)->toBe($now);
    expect($event->rawPayload)->toBe('{"status":"success"}');
    expect($event->providerTimestamp)->toBe($now);
    expect($event->metadata)->toBe(['region' => 'BW']);
    expect($event->isResolved())->toBeTrue();
});

// ---------------------------------------------------------------------------
// unresolved() factory
// ---------------------------------------------------------------------------

test('unresolved() factory sets platformEntityId to null and isResolved() returns false', function () use ($now) {
    $event = NormalizedWebhookEvent::unresolved(
        idempotencyKey:      'idem-002',
        correlationId:       'corr-002',
        providerKey:         'mno_primary',
        providerReference:   'TXN-002',
        normalizedEventType: 'payment.pending',
        status:              ProviderStatus::UNKNOWN,
        receivedAt:          $now,
        rawPayload:          '{"status":"pending"}',
    );

    expect($event->platformEntityId)->toBeNull();
    expect($event->isResolved())->toBeFalse();
    expect($event->providerReference)->toBe('TXN-002');
    expect($event->providerKey)->toBe('mno_primary');
    expect($event->status)->toBe(ProviderStatus::UNKNOWN);
});

test('unresolved() factory accepts null providerReference', function () use ($now) {
    $event = NormalizedWebhookEvent::unresolved(
        idempotencyKey:      'idem-003',
        correlationId:       'corr-003',
        providerKey:         'mno_primary',
        providerReference:   null,
        normalizedEventType: 'payment.received',
        status:              ProviderStatus::UNKNOWN,
        receivedAt:          $now,
        rawPayload:          '{"raw":"data"}',
    );

    expect($event->providerReference)->toBeNull();
    expect($event->isResolved())->toBeFalse();
});

test('unresolved() factory allows null platformEntityType', function () use ($now) {
    $event = NormalizedWebhookEvent::unresolved(
        idempotencyKey:      'idem-004',
        correlationId:       'corr-004',
        providerKey:         'mno_primary',
        providerReference:   null,
        normalizedEventType: 'payment.received',
        status:              ProviderStatus::UNKNOWN,
        receivedAt:          $now,
        rawPayload:          '{"x":1}',
        platformEntityType:  null,
    );

    expect($event->platformEntityType)->toBeNull();
});

// ---------------------------------------------------------------------------
// Constructor validation
// ---------------------------------------------------------------------------

test('constructor throws for empty idempotencyKey', function () use ($now) {
    expect(fn () => new NormalizedWebhookEvent(
        idempotencyKey:      '',
        correlationId:       'corr-001',
        platformEntityId:    null,
        platformEntityType:  null,
        providerKey:         'mno_primary',
        providerReference:   null,
        normalizedEventType: 'payment.success',
        status:              ProviderStatus::SUCCESS,
        receivedAt:          $now,
        rawPayload:          '{"x":1}',
    ))->toThrow(\InvalidArgumentException::class);
});

test('constructor throws for empty correlationId', function () use ($now) {
    expect(fn () => new NormalizedWebhookEvent(
        idempotencyKey:      'idem-005',
        correlationId:       '',
        platformEntityId:    null,
        platformEntityType:  null,
        providerKey:         'mno_primary',
        providerReference:   null,
        normalizedEventType: 'payment.success',
        status:              ProviderStatus::SUCCESS,
        receivedAt:          $now,
        rawPayload:          '{"x":1}',
    ))->toThrow(\InvalidArgumentException::class);
});

test('constructor throws for empty providerKey', function () use ($now) {
    expect(fn () => new NormalizedWebhookEvent(
        idempotencyKey:      'idem-006',
        correlationId:       'corr-006',
        platformEntityId:    null,
        platformEntityType:  null,
        providerKey:         '',
        providerReference:   null,
        normalizedEventType: 'payment.success',
        status:              ProviderStatus::SUCCESS,
        receivedAt:          $now,
        rawPayload:          '{"x":1}',
    ))->toThrow(\InvalidArgumentException::class);
});

test('constructor throws for empty normalizedEventType', function () use ($now) {
    expect(fn () => new NormalizedWebhookEvent(
        idempotencyKey:      'idem-007',
        correlationId:       'corr-007',
        platformEntityId:    null,
        platformEntityType:  null,
        providerKey:         'mno_primary',
        providerReference:   null,
        normalizedEventType: '',
        status:              ProviderStatus::SUCCESS,
        receivedAt:          $now,
        rawPayload:          '{"x":1}',
    ))->toThrow(\InvalidArgumentException::class);
});

test('constructor throws for empty rawPayload', function () use ($now) {
    expect(fn () => new NormalizedWebhookEvent(
        idempotencyKey:      'idem-008',
        correlationId:       'corr-008',
        platformEntityId:    null,
        platformEntityType:  null,
        providerKey:         'mno_primary',
        providerReference:   null,
        normalizedEventType: 'payment.success',
        status:              ProviderStatus::SUCCESS,
        receivedAt:          $now,
        rawPayload:          '',
    ))->toThrow(\InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Optional fields and defaults
// ---------------------------------------------------------------------------

test('providerTimestamp is nullable and defaults to null when not provided', function () use ($now) {
    $event = NormalizedWebhookEvent::unresolved(
        idempotencyKey:      'idem-009',
        correlationId:       'corr-009',
        providerKey:         'mno_primary',
        providerReference:   null,
        normalizedEventType: 'payment.success',
        status:              ProviderStatus::SUCCESS,
        receivedAt:          $now,
        rawPayload:          '{"x":1}',
    );

    expect($event->providerTimestamp)->toBeNull();
});

test('amount and currency default to null for non-financial events', function () use ($now) {
    $event = NormalizedWebhookEvent::unresolved(
        idempotencyKey:      'idem-010',
        correlationId:       'corr-010',
        providerKey:         'mno_primary',
        providerReference:   null,
        normalizedEventType: 'kyc.completed',
        status:              ProviderStatus::SUCCESS,
        receivedAt:          $now,
        rawPayload:          '{"x":1}',
    );

    expect($event->amount)->toBeNull();
    expect($event->currency)->toBeNull();
});

test('metadata defaults to empty array when not provided', function () use ($now) {
    $event = NormalizedWebhookEvent::unresolved(
        idempotencyKey:      'idem-011',
        correlationId:       'corr-011',
        providerKey:         'mno_primary',
        providerReference:   null,
        normalizedEventType: 'payment.success',
        status:              ProviderStatus::SUCCESS,
        receivedAt:          $now,
        rawPayload:          '{"x":1}',
    );

    expect($event->metadata)->toBe([]);
});

// ---------------------------------------------------------------------------
// Data model field name conformance
// ---------------------------------------------------------------------------

test('normalizedEventType field name matches data model (not eventType)', function () use ($now) {
    $event = NormalizedWebhookEvent::unresolved(
        idempotencyKey:      'idem-012',
        correlationId:       'corr-012',
        providerKey:         'mno_primary',
        providerReference:   null,
        normalizedEventType: 'payment.reversed',
        status:              ProviderStatus::FAILURE,
        receivedAt:          $now,
        rawPayload:          '{"x":1}',
    );

    expect($event->normalizedEventType)->toBe('payment.reversed');
    expect(property_exists($event, 'eventType'))->toBeFalse();
});

test('providerTimestamp field name matches data model (not occurredAt)', function () use ($now) {
    $event = NormalizedWebhookEvent::resolved(
        idempotencyKey:      'idem-013',
        correlationId:       'corr-013',
        platformEntityId:    'entity-013',
        platformEntityType:  'transaction',
        providerKey:         'mno_primary',
        providerReference:   'TXN-013',
        normalizedEventType: 'payment.success',
        status:              ProviderStatus::SUCCESS,
        receivedAt:          $now,
        rawPayload:          '{"x":1}',
        providerTimestamp:   $now,
    );

    expect($event->providerTimestamp)->toBe($now);
    expect(property_exists($event, 'occurredAt'))->toBeFalse();
});
