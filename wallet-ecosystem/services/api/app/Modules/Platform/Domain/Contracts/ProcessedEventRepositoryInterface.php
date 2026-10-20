<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

/**
 * Contract for consumer-side event idempotency.
 *
 * Each consuming module MUST create its own processed_events table in its own schema and
 * implement this interface — Platform Foundation provides only the interface contract and
 * migration template. A shared cross-module processed_events table is forbidden (violates
 * schema-per-module isolation).
 */
interface ProcessedEventRepositoryInterface
{
    public function hasBeenProcessed(string $eventId, string $consumerClass): bool;

    public function markAsProcessed(string $eventId, string $consumerClass, \DateTimeImmutable $processedAt): void;
}
