<?php

declare(strict_types=1);

namespace Tests\Support\Factories;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AuditEventFactory
{
    private string  $actorId   = '';
    private string  $actorType = 'USER';
    private string  $action    = 'platform.test.action';
    private string  $entityType = 'Test';
    private string  $entityId  = '';
    private array   $before    = [];
    private array   $after     = [];
    private ?string $correlationId = null;

    public function __construct()
    {
        $this->actorId        = (string) Uuid::uuid7();
        $this->entityId       = (string) Uuid::uuid7();
        $this->correlationId  = (string) Uuid::uuid7();
    }

    public static function new(): self
    {
        return new self();
    }

    public function withActorId(string $actorId): self
    {
        $clone          = clone $this;
        $clone->actorId = $actorId;
        return $clone;
    }

    public function withAction(string $action): self
    {
        $clone         = clone $this;
        $clone->action = $action;
        return $clone;
    }

    public function withEntityType(string $type): self
    {
        $clone             = clone $this;
        $clone->entityType = $type;
        return $clone;
    }

    public function withBefore(array $before): self
    {
        $clone         = clone $this;
        $clone->before = $before;
        return $clone;
    }

    public function withAfter(array $after): self
    {
        $clone        = clone $this;
        $clone->after = $after;
        return $clone;
    }

    public function create(): object
    {
        $id = (string) Uuid::uuid7();

        DB::connection('platform')->table('platform.audit_events')->insert([
            'id'             => $id,
            'correlation_id' => $this->correlationId,
            'causation_id'   => null,
            'actor_id'       => $this->actorId,
            'actor_type'     => $this->actorType,
            'action'         => $this->action,
            'entity_type'    => $this->entityType,
            'entity_id'      => $this->entityId,
            'before_state'   => json_encode($this->before) ?: null,
            'after_state'    => json_encode($this->after) ?: null,
            'metadata'       => json_encode([]),
            'ip_address'     => null,
            'user_agent'     => null,
            'created_at'     => now(),
        ]);

        return (object) [
            'id'          => $id,
            'actor_id'    => $this->actorId,
            'action'      => $this->action,
            'entity_type' => $this->entityType,
            'entity_id'   => $this->entityId,
        ];
    }
}
