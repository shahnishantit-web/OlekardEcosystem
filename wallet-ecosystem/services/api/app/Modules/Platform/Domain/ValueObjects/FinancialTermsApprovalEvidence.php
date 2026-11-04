<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use DateTimeImmutable;

/** Finance/Governance-owned evidence bound to one exact terms content hash and version. */
final readonly class FinancialTermsApprovalEvidence
{
    public function __construct(
        public string $reference,
        public string $approverActorId,
        public int $termsVersion,
        public string $contentHash,
        public DateTimeImmutable $approvedAt,
        public string $evidenceProvenance,
    ) {
        if ($reference === '' || mb_strlen($reference) > 255 || $approverActorId === '' || $termsVersion < 1
            || preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1
            || $evidenceProvenance === '' || mb_strlen($evidenceProvenance) > 255) {
            throw new \InvalidArgumentException('Financial terms approval evidence is incomplete.');
        }
    }

    public function assertMatches(string $actorId, int $termsVersion, string $contentHash, DateTimeImmutable $now): void
    {
        if ($this->approverActorId !== $actorId || $this->termsVersion !== $termsVersion
            || ! hash_equals($this->contentHash, $contentHash) || $this->approvedAt > $now) {
            throw new \DomainException('Verified approval evidence does not match this terms version, content or approver.');
        }
    }
}
