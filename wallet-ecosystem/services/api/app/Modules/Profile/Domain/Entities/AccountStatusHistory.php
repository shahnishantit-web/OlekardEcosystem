<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Entities;

/**
 * Read-only domain entity representing a single account status transition entry.
 * Corresponds to one row in profile.account_status_history.
 * No Eloquent dependency.
 */
final readonly class AccountStatusHistory
{
    /**
     * @param list<string>|null $closureObligations
     */
    public function __construct(
        public string       $id,
        public string       $userId,
        public ?string      $fromStatus,
        public string       $toStatus,
        public ?string      $reason,
        public string       $actorId,
        public string       $actorType,
        public ?array       $closureObligations,
        public ?string      $correlationId,
        public string       $createdAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $obligations = $data['closure_obligations'] ?? null;
        if (is_string($obligations)) {
            $obligations = json_decode($obligations, true) ?? null;
        }

        return new static(
            id:                  (string) $data['id'],
            userId:              (string) $data['user_id'],
            fromStatus:          isset($data['from_status']) ? (string) $data['from_status'] : null,
            toStatus:            (string) $data['to_status'],
            reason:              isset($data['reason'])        ? (string) $data['reason']        : null,
            actorId:             (string) $data['actor_id'],
            actorType:           (string) $data['actor_type'],
            closureObligations:  is_array($obligations) ? array_values($obligations) : null,
            correlationId:       isset($data['correlation_id']) ? (string) $data['correlation_id'] : null,
            createdAt:           (string) $data['created_at'],
        );
    }
}
