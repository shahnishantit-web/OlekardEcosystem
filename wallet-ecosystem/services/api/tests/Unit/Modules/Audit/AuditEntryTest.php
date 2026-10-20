<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Audit;

use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    public function test_make_creates_entry_with_required_fields(): void
    {
        $entry = AuditEntry::make(
            category:      AuditEventCategory::Authentication,
            eventType:     'authentication.login.success',
            actorType:     AuditActorType::Customer,
            action:        'User logged in',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-123',
        );

        $this->assertSame(AuditEventCategory::Authentication, $entry->category);
        $this->assertSame('authentication.login.success', $entry->eventType);
        $this->assertSame(AuditActorType::Customer, $entry->actorType);
        $this->assertSame('User logged in', $entry->action);
        $this->assertSame(AuditOutcome::Success, $entry->outcome);
        $this->assertSame('corr-123', $entry->correlationId);
        $this->assertNull($entry->actorId);
        $this->assertNull($entry->entityType);
        $this->assertNull($entry->entityId);
        $this->assertNull($entry->beforeState);
        $this->assertNull($entry->afterState);
        $this->assertSame([], $entry->metadata);
        $this->assertNull($entry->occurredAt);
    }

    public function test_with_actor_returns_new_instance_with_actor_id(): void
    {
        $original = AuditEntry::make(
            category:      AuditEventCategory::Wallet,
            eventType:     'wallet.created',
            actorType:     AuditActorType::Admin,
            action:        'Wallet created',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-456',
        );

        $updated = $original->withActor('user-uuid-789');

        $this->assertNotSame($original, $updated);
        $this->assertNull($original->actorId);
        $this->assertSame('user-uuid-789', $updated->actorId);
        // Other fields unchanged
        $this->assertSame($original->category, $updated->category);
        $this->assertSame($original->correlationId, $updated->correlationId);
    }

    public function test_with_entity_returns_new_instance(): void
    {
        $entry = AuditEntry::make(
            category:      AuditEventCategory::Transaction,
            eventType:     'transaction.completed',
            actorType:     AuditActorType::System,
            action:        'Transfer completed',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-789',
        )->withEntity('transaction', 'tx-uuid-001');

        $this->assertSame('transaction', $entry->entityType);
        $this->assertSame('tx-uuid-001', $entry->entityId);
    }

    public function test_with_before_and_after_state(): void
    {
        $entry = AuditEntry::make(
            category:      AuditEventCategory::Admin,
            eventType:     'admin.user.status_changed',
            actorType:     AuditActorType::Admin,
            action:        'User status updated',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-abc',
        )
        ->withBeforeState(['status' => 'active'])
        ->withAfterState(['status' => 'suspended']);

        $this->assertSame(['status' => 'active'], $entry->beforeState);
        $this->assertSame(['status' => 'suspended'], $entry->afterState);
    }

    public function test_with_metadata_merges_values(): void
    {
        $entry = AuditEntry::make(
            category:      AuditEventCategory::Security,
            eventType:     'security.brute_force.lockout',
            actorType:     AuditActorType::System,
            action:        'Account locked after failed attempts',
            outcome:       AuditOutcome::Denied,
            correlationId: 'corr-sec',
        )
        ->withMetadata(['source_ip' => '1.2.3.4'])
        ->withMetadata(['attempts' => 5]);

        $this->assertSame(['source_ip' => '1.2.3.4', 'attempts' => 5], $entry->metadata);
    }

    public function test_with_occurred_at_sets_timestamp(): void
    {
        $at    = CarbonImmutable::parse('2026-09-28T10:00:00Z');
        $entry = AuditEntry::make(
            category:      AuditEventCategory::Kyc,
            eventType:     'kyc.tier.upgraded',
            actorType:     AuditActorType::System,
            action:        'KYC tier upgraded to 1',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-kyc',
        )->withOccurredAt($at);

        $this->assertSame($at, $entry->occurredAt);
    }

    public function test_builder_is_immutable(): void
    {
        $base    = AuditEntry::make(
            category:      AuditEventCategory::Config,
            eventType:     'config.fee_rate.changed',
            actorType:     AuditActorType::Admin,
            action:        'Fee rate updated',
            outcome:       AuditOutcome::Success,
            correlationId: 'corr-cfg',
        );
        $withActor  = $base->withActor('admin-uuid');
        $withEntity = $base->withEntity('fee_rule', 'rule-uuid');

        // $base remains unchanged
        $this->assertNull($base->actorId);
        $this->assertNull($base->entityType);
        // Derived instances are independent
        $this->assertSame('admin-uuid', $withActor->actorId);
        $this->assertNull($withActor->entityType);
        $this->assertSame('fee_rule', $withEntity->entityType);
        $this->assertNull($withEntity->actorId);
    }
}
