<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects\Providers;

use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\ValueObjects\Currency;
use App\Modules\Platform\Domain\ValueObjects\Money;

/**
 * Provider-neutral webhook event produced by ProviderWebhookContract::parse().
 *
 * All fields are readonly. Construct via the static factories or directly.
 * platformEntityId is nullable — null means the webhook could not be resolved
 * to a known platform entity at parse time (deferred resolution per FR-E1000-019).
 * rawPayload is preserved verbatim for audit and replay; it MUST NOT be processed
 * by domain logic directly.
 */
final readonly class NormalizedWebhookEvent
{
    /**
     * @param array<string, mixed> $metadata  Additional normalized metadata — MUST NOT contain provider credentials.
     */
    public function __construct(
        /** Globally unique idempotency key for this webhook delivery (use for deduplication). */
        public string $idempotencyKey,
        /** Platform correlation ID threading this event through the request chain. */
        public string $correlationId,
        /** Resolved platform entity ID, or null if unresolvable at parse time. */
        public ?string $platformEntityId,
        /** Platform entity type discriminator, or null if unresolvable at parse time. */
        public ?string $platformEntityType,
        /** Configuration key identifying the provider that sent this webhook. */
        public string $providerKey,
        /** Provider-assigned reference, if present in the payload. */
        public ?string $providerReference,
        /** Platform-defined normalized event type (not the raw provider-specific string). */
        public string $normalizedEventType,
        /** Normalized outcome status for this event. */
        public ProviderStatus $status,
        /** UTC timestamp set by the E25 caller when the webhook was received by the platform. */
        public \DateTimeImmutable $receivedAt,
        /** Raw payload bytes — preserved for auditability; never processed by domain logic. */
        public string $rawPayload,
        /** Timestamp at which the provider claims the event occurred (UTC); null if not provided. */
        public ?\DateTimeImmutable $providerTimestamp = null,
        /** Financial amount if the event carries a monetary value; null for non-financial events. */
        public ?Money $amount = null,
        /** Currency of the financial amount; null for non-financial events. */
        public ?Currency $currency = null,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('NormalizedWebhookEvent: idempotencyKey must not be empty.');
        }
        if ($correlationId === '') {
            throw new \InvalidArgumentException('NormalizedWebhookEvent: correlationId must not be empty.');
        }
        if ($providerKey === '') {
            throw new \InvalidArgumentException('NormalizedWebhookEvent: providerKey must not be empty.');
        }
        if ($normalizedEventType === '') {
            throw new \InvalidArgumentException('NormalizedWebhookEvent: normalizedEventType must not be empty.');
        }
        if ($rawPayload === '') {
            throw new \InvalidArgumentException('NormalizedWebhookEvent: rawPayload must not be empty.');
        }
    }

    /**
     * Convenience factory when the webhook is resolved to a known platform entity.
     *
     * @param array<string, mixed> $metadata
     */
    public static function resolved(
        string $idempotencyKey,
        string $correlationId,
        string $platformEntityId,
        string $platformEntityType,
        string $providerKey,
        ?string $providerReference,
        string $normalizedEventType,
        ProviderStatus $status,
        \DateTimeImmutable $receivedAt,
        string $rawPayload,
        ?\DateTimeImmutable $providerTimestamp = null,
        ?Money $amount = null,
        ?Currency $currency = null,
        array $metadata = [],
    ): self {
        return new self(
            idempotencyKey:      $idempotencyKey,
            correlationId:       $correlationId,
            platformEntityId:    $platformEntityId,
            platformEntityType:  $platformEntityType,
            providerKey:         $providerKey,
            providerReference:   $providerReference,
            normalizedEventType: $normalizedEventType,
            status:              $status,
            receivedAt:          $receivedAt,
            rawPayload:          $rawPayload,
            providerTimestamp:   $providerTimestamp,
            amount:              $amount,
            currency:            $currency,
            metadata:            $metadata,
        );
    }

    /**
     * Factory for webhooks that cannot be resolved to a platform entity at parse time.
     * E25 or a reconciliation pass must resolve platformEntityId before processing.
     *
     * @param array<string, mixed> $metadata
     */
    public static function unresolved(
        string $idempotencyKey,
        string $correlationId,
        string $providerKey,
        ?string $providerReference,
        string $normalizedEventType,
        ProviderStatus $status,
        \DateTimeImmutable $receivedAt,
        string $rawPayload,
        ?\DateTimeImmutable $providerTimestamp = null,
        ?string $platformEntityType = null,
        ?Money $amount = null,
        ?Currency $currency = null,
        array $metadata = [],
    ): self {
        return new self(
            idempotencyKey:      $idempotencyKey,
            correlationId:       $correlationId,
            platformEntityId:    null,
            platformEntityType:  $platformEntityType,
            providerKey:         $providerKey,
            providerReference:   $providerReference,
            normalizedEventType: $normalizedEventType,
            status:              $status,
            receivedAt:          $receivedAt,
            rawPayload:          $rawPayload,
            providerTimestamp:   $providerTimestamp,
            amount:              $amount,
            currency:            $currency,
            metadata:            $metadata,
        );
    }

    public function isResolved(): bool
    {
        return $this->platformEntityId !== null;
    }
}
