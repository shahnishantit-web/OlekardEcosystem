<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\ValueObjects;

use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use Carbon\CarbonImmutable;

/**
 * Value object carrying all fields required to record one audit event.
 *
 * Instantiation examples:
 *
 *   // Authentication success
 *   AuditEntry::make(
 *       category:      AuditEventCategory::Authentication,
 *       eventType:     'authentication.login.success',
 *       actorType:     AuditActorType::Customer,
 *       action:        'User logged in via email/password',
 *       outcome:       AuditOutcome::Success,
 *       correlationId: $correlationId,
 *   )->withActor($userId)->withMetadata(['source_ip' => $ip]);
 *
 *   // Wallet suspended (admin action)
 *   AuditEntry::make(
 *       category:      AuditEventCategory::Wallet,
 *       eventType:     'wallet.suspended',
 *       actorType:     AuditActorType::Admin,
 *       action:        "Wallet {$walletId} suspended",
 *       outcome:       AuditOutcome::Success,
 *       correlationId: $correlationId,
 *   )->withActor($adminId)->withEntity('wallet', $walletId)->withBeforeState(['status' => 'active'])->withAfterState(['status' => 'suspended']);
 */
final readonly class AuditEntry
{
    private function __construct(
        public AuditEventCategory $category,
        public string             $eventType,
        public AuditActorType     $actorType,
        public string             $action,
        public AuditOutcome       $outcome,
        public string             $correlationId,
        public ?string            $actorId      = null,
        public ?string            $entityType   = null,
        public ?string            $entityId     = null,
        public ?array             $beforeState  = null,
        public ?array             $afterState   = null,
        public array              $metadata     = [],
        public ?CarbonImmutable   $occurredAt   = null,
    ) {}

    public static function make(
        AuditEventCategory $category,
        string             $eventType,
        AuditActorType     $actorType,
        string             $action,
        AuditOutcome       $outcome,
        string             $correlationId,
    ): self {
        return new self(
            category:      $category,
            eventType:     $eventType,
            actorType:     $actorType,
            action:        $action,
            outcome:       $outcome,
            correlationId: $correlationId,
        );
    }

    public function withActor(string $actorId): self
    {
        return new self(...array_merge((array) $this, ['actorId' => $actorId]));
    }

    public function withEntity(string $entityType, string $entityId): self
    {
        return new self(...array_merge((array) $this, ['entityType' => $entityType, 'entityId' => $entityId]));
    }

    public function withBeforeState(array $state): self
    {
        return new self(...array_merge((array) $this, ['beforeState' => $state]));
    }

    public function withAfterState(array $state): self
    {
        return new self(...array_merge((array) $this, ['afterState' => $state]));
    }

    public function withMetadata(array $metadata): self
    {
        return new self(...array_merge((array) $this, ['metadata' => array_merge($this->metadata, $metadata)]));
    }

    public function withOccurredAt(CarbonImmutable $at): self
    {
        return new self(...array_merge((array) $this, ['occurredAt' => $at]));
    }
}
