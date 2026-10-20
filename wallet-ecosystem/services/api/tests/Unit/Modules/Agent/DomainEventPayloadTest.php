<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Events\AgentApplicationCreatedEvent;
use App\Modules\Agent\Domain\Events\AgentApplicationPersonalInfoUpdatedEvent;
use App\Modules\Agent\Domain\Events\AgentApplicationDocumentUploadedEvent;
use App\Modules\Agent\Domain\Events\AgentApplicationDocumentRejectedEvent;
use App\Modules\Agent\Domain\Events\AgentApplicationKycBypassedEvent;
use App\Modules\Agent\Domain\Events\AgentApprovedEvent;
use App\Modules\Agent\Domain\Events\AgentSuspendedEvent;
use App\Modules\Agent\Domain\Events\AgentReactivatedEvent;
use App\Modules\Agent\Domain\Events\AgentCustomerRegisteredEvent;
use App\Modules\Agent\Domain\Events\AgentCustomerKycInitiatedEvent;

// T113 ————————————————————————————————————————————————

if (!function_exists('eid')) {
    function eid(): string { return \Ramsey\Uuid\Uuid::uuid7()->toString(); }
}
if (!function_exists('ets')) {
    function ets(): string { return now()->toIso8601String(); }
}

test('AgentApplicationCreatedEvent has correct structure and eventName', function () {
    $id   = eid();
    $corr = eid();
    $ts   = ets();

    $event = new AgentApplicationCreatedEvent(
        applicationId: $id,
        userId:        eid(),
        agentType:     'individual',
        occurredAt:    $ts,
        correlationId: $corr,
    );

    expect($event->eventName())->toBe('agent.application.created');
    expect($event->applicationId)->toBe($id);
    expect($event->correlationId)->toBe($corr);
    expect($event->occurredAt)->toBe($ts);
});

test('AgentApplicationPersonalInfoUpdatedEvent has required fields', function () {
    $event = new AgentApplicationPersonalInfoUpdatedEvent(
        applicationId: eid(),
        actorId:       eid(),
        occurredAt:    ets(),
        correlationId: eid(),
    );

    expect($event->eventName())->toBe('agent.application.personal_info_updated');
    expect($event->actorId)->not->toBeEmpty();
});

test('AgentApplicationDocumentUploadedEvent has documentType and documentId fields', function () {
    $event = new AgentApplicationDocumentUploadedEvent(
        applicationId: eid(),
        documentId:    eid(),
        documentType:  'national_id',
        actorId:       eid(),
        occurredAt:    ets(),
        correlationId: eid(),
    );

    expect($event->eventName())->toBe('agent.application.document_uploaded');
    expect($event->documentType)->toBe('national_id');
    expect($event->documentId)->not->toBeEmpty();
});

test('AgentApplicationDocumentRejectedEvent has actorType and reason fields', function () {
    $event = new AgentApplicationDocumentRejectedEvent(
        applicationId: eid(),
        documentId:    eid(),
        documentType:  'national_id',
        actorType:     'SYSTEM',
        reason:        'virus_detected',
        occurredAt:    ets(),
        correlationId: eid(),
    );

    expect($event->eventName())->toBe('agent.application.document_rejected');
    expect($event->actorType)->toBe('SYSTEM');
    expect($event->reason)->toBe('virus_detected');
});

test('AgentApprovedEvent has agentId, applicationId, userId, approvedBy fields', function () {
    $agentId = eid();
    $event   = new AgentApprovedEvent(
        agentId:       $agentId,
        applicationId: eid(),
        userId:        eid(),
        approvedBy:    eid(),
        occurredAt:    ets(),
        correlationId: eid(),
    );

    expect($event->eventName())->toBe('agent.approved');
    expect($event->agentId)->toBe($agentId);
    expect($event->approvedBy)->not->toBeEmpty();
});

test('AgentSuspendedEvent has agentId, suspendedBy, reason fields (no userId)', function () {
    $agentId = eid();
    $event   = new AgentSuspendedEvent(
        agentId:       $agentId,
        suspendedBy:   eid(),
        reason:        'Suspicious activity.',
        occurredAt:    ets(),
        correlationId: eid(),
    );

    expect($event->eventName())->toBe('agent.suspended');
    expect($event->agentId)->toBe($agentId);
    expect($event->reason)->not->toBeEmpty();
    expect($event->suspendedBy)->not->toBeEmpty();
});

test('AgentReactivatedEvent has correct eventName', function () {
    $event = new AgentReactivatedEvent(
        agentId:       eid(),
        reactivatedBy: eid(),
        occurredAt:    ets(),
        correlationId: eid(),
    );

    expect($event->eventName())->toBe('agent.reactivated');
});

test('AgentApplicationKycBypassedEvent has adminActorId field', function () {
    $event = new AgentApplicationKycBypassedEvent(
        applicationId: eid(),
        adminActorId:  eid(),
        occurredAt:    ets(),
        correlationId: eid(),
    );

    expect($event->eventName())->toBe('agent.application.kyc_bypassed');
    expect($event->adminActorId)->not->toBeEmpty();
    $payload = $event->toArray();
    expect($payload['bypass_reason'])->toBe('ADMIN_KYC_BYPASS');
});

test('AgentCustomerRegisteredEvent has agentId and customerId', function () {
    $event = new AgentCustomerRegisteredEvent(
        agentId:       eid(),
        customerId:    eid(),
        actorId:       eid(),
        occurredAt:    ets(),
        correlationId: eid(),
    );

    expect($event->eventName())->toBe('agent.customer.registered');
    expect($event->customerId)->not->toBeEmpty();
});

test('AgentCustomerKycInitiatedEvent has correct eventName', function () {
    $event = new AgentCustomerKycInitiatedEvent(
        agentId:       eid(),
        customerId:    eid(),
        actorId:       eid(),
        occurredAt:    ets(),
        correlationId: eid(),
    );

    expect($event->eventName())->toBe('agent.customer.kyc_initiated');
});
