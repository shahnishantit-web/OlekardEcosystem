<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\ValueObjects;

use DateTimeImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Verifiable, scope-bound approval facts returned by the Governance-owned approval boundary. */
final readonly class ComplimentaryAllocationApprovalEvidence
{
    public function __construct(
        public string $approvalId,
        public int $version,
        public string $organisationId,
        public string $eventId,
        public string $categoryId,
        public int $approvedQuota,
        public string $requestedByActorId,
        public string $approvedByActorId,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt,
        public string $provenance,
    ) {}

    public function assertAppliesTo(
        string $organisationId,
        string $eventId,
        string $categoryId,
        int $requiredQuota,
        DateTimeImmutable $at,
    ): void {
        if ($this->approvalId === '' || $this->version < 1 || $this->provenance === ''
            || $this->requestedByActorId === '' || $this->approvedByActorId === ''
            || $this->requestedByActorId === $this->approvedByActorId
            || $this->organisationId !== $organisationId || $this->eventId !== $eventId
            || $this->categoryId !== $categoryId || $this->approvedQuota < $requiredQuota
            || $requiredQuota < 1 || $this->validFrom >= $this->expiresAt
            || $at < $this->validFrom || $at >= $this->expiresAt || $this->revokedAt !== null) {
            throw new HttpException(403, 'Complimentary allocation approval is invalid or unavailable.');
        }
    }

    /** @return array<string, int|string|null> */
    public function toSnapshot(): array
    {
        return [
            'approval_id' => $this->approvalId,
            'version' => $this->version,
            'organisation_id' => $this->organisationId,
            'event_id' => $this->eventId,
            'category_id' => $this->categoryId,
            'approved_quota' => $this->approvedQuota,
            'requested_by_actor_id' => $this->requestedByActorId,
            'approved_by_actor_id' => $this->approvedByActorId,
            'valid_from' => $this->validFrom->format(DATE_ATOM),
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'revoked_at' => $this->revokedAt?->format(DATE_ATOM),
            'provenance' => $this->provenance,
        ];
    }
}
