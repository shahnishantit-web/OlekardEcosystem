<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\ValueObjects;

use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use Carbon\CarbonImmutable;

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
        return new self(
            category:      $this->category,
            eventType:     $this->eventType,
            actorType:     $this->actorType,
            action:        $this->action,
            outcome:       $this->outcome,
            correlationId: $this->correlationId,
            actorId:       $actorId,
            entityType:    $this->entityType,
            entityId:      $this->entityId,
            beforeState:   $this->beforeState,
            afterState:    $this->afterState,
            metadata:      $this->metadata,
            occurredAt:    $this->occurredAt,
        );
    }

    public function withEntity(string $entityType, string $entityId): self
    {
        return new self(
            category:      $this->category,
            eventType:     $this->eventType,
            actorType:     $this->actorType,
            action:        $this->action,
            outcome:       $this->outcome,
            correlationId: $this->correlationId,
            actorId:       $this->actorId,
            entityType:    $entityType,
            entityId:      $entityId,
            beforeState:   $this->beforeState,
            afterState:    $this->afterState,
            metadata:      $this->metadata,
            occurredAt:    $this->occurredAt,
        );
    }

    public function withBeforeState(array $state): self
    {
        return new self(
            category:      $this->category,
            eventType:     $this->eventType,
            actorType:     $this->actorType,
            action:        $this->action,
            outcome:       $this->outcome,
            correlationId: $this->correlationId,
            actorId:       $this->actorId,
            entityType:    $this->entityType,
            entityId:      $this->entityId,
            beforeState:   $state,
            afterState:    $this->afterState,
            metadata:      $this->metadata,
            occurredAt:    $this->occurredAt,
        );
    }

    public function withAfterState(array $state): self
    {
        return new self(
            category:      $this->category,
            eventType:     $this->eventType,
            actorType:     $this->actorType,
            action:        $this->action,
            outcome:       $this->outcome,
            correlationId: $this->correlationId,
            actorId:       $this->actorId,
            entityType:    $this->entityType,
            entityId:      $this->entityId,
            beforeState:   $this->beforeState,
            afterState:    $state,
            metadata:      $this->metadata,
            occurredAt:    $this->occurredAt,
        );
    }

    public function withMetadata(array $metadata): self
    {
        return new self(
            category:      $this->category,
            eventType:     $this->eventType,
            actorType:     $this->actorType,
            action:        $this->action,
            outcome:       $this->outcome,
            correlationId: $this->correlationId,
            actorId:       $this->actorId,
            entityType:    $this->entityType,
            entityId:      $this->entityId,
            beforeState:   $this->beforeState,
            afterState:    $this->afterState,
            metadata:      array_merge($this->metadata, $metadata),
            occurredAt:    $this->occurredAt,
        );
    }

    public function withOccurredAt(CarbonImmutable $at): self
    {
        return new self(
            category:      $this->category,
            eventType:     $this->eventType,
            actorType:     $this->actorType,
            action:        $this->action,
            outcome:       $this->outcome,
            correlationId: $this->correlationId,
            actorId:       $this->actorId,
            entityType:    $this->entityType,
            entityId:      $this->entityId,
            beforeState:   $this->beforeState,
            afterState:    $this->afterState,
            metadata:      $this->metadata,
            occurredAt:    $at,
        );
    }
}
