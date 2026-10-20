<?php

declare(strict_types=1);

namespace Tests\Support\Factories;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class OutboxEventFactory
{
    private string $eventName    = 'Platform.Test.Event';
    private string $eventVersion = '1.0';
    private string $aggregateType = 'Test';
    private string $status        = 'pending';
    private int    $attempts      = 0;
    private int    $maxAttempts   = 5;
    private array  $payload       = [];

    public static function new(): self
    {
        return new self();
    }

    public function withEventName(string $name): self
    {
        $clone            = clone $this;
        $clone->eventName = $name;
        return $clone;
    }

    public function withStatus(string $status): self
    {
        $clone         = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function withAttempts(int $count): self
    {
        $clone           = clone $this;
        $clone->attempts = $count;
        return $clone;
    }

    public function withPayload(array $payload): self
    {
        $clone          = clone $this;
        $clone->payload = $payload;
        return $clone;
    }

    public function deadLetter(): self
    {
        $clone           = clone $this;
        $clone->status   = 'dead_letter';
        $clone->attempts = $this->maxAttempts;
        return $clone;
    }

    public function create(): object
    {
        $id = (string) Uuid::uuid7();

        DB::connection('platform')->table('platform.outbox_events')->insert([
            'id'              => $id,
            'aggregate_id'    => (string) Uuid::uuid7(),
            'aggregate_type'  => $this->aggregateType,
            'actor_id'        => null,
            'correlation_id'  => (string) Uuid::uuid7(),
            'causation_id'    => null,
            'event_name'      => $this->eventName,
            'event_version'   => $this->eventVersion,
            'payload'         => json_encode($this->payload),
            'metadata'        => json_encode([]),
            'status'          => $this->status,
            'attempts'        => $this->attempts,
            'max_attempts'    => $this->maxAttempts,
            'next_attempt_at' => null,
            'created_at'      => now(),
        ]);

        return (object) ['id' => $id, 'event_name' => $this->eventName];
    }
}
