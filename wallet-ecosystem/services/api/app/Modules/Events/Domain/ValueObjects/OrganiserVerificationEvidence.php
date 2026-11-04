<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\ValueObjects;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/** Immutable proof returned only by an authoritative organiser-verification provider. */
final readonly class OrganiserVerificationEvidence
{
    public function __construct(
        public string $organisationId,
        public string $eventId,
        public string $verificationId,
        public int $version,
        public string $organisationIdentityEvidenceReference,
        public string $representativeAuthorityEvidenceReference,
        public string $eventOrganisingAuthorityEvidenceReference,
        public DateTimeImmutable $verifiedAt,
        public DateTimeImmutable $validUntil,
    ) {
        if ($organisationId === '' || $eventId === '' || $verificationId === ''
            || $version < 1 || $organisationIdentityEvidenceReference === ''
            || $representativeAuthorityEvidenceReference === ''
            || $eventOrganisingAuthorityEvidenceReference === '' || $verifiedAt >= $validUntil) {
            throw ValidationException::withMessages(['organiser_verification' => 'Organiser verification evidence is invalid.']);
        }
    }

    public function assertAppliesTo(string $organisationId, string $eventId, DateTimeImmutable $now): void
    {
        if ($this->organisationId !== $organisationId || $this->eventId !== $eventId
            || $this->verifiedAt > $now || $this->validUntil <= $now) {
            throw ValidationException::withMessages(['organiser_verification' => 'Current organiser verification for this event is required.']);
        }
    }

    /** @return array<string, string|int> */
    public function toSnapshot(): array
    {
        return [
            'organisation_id' => $this->organisationId,
            'event_id' => $this->eventId,
            'verification_id' => $this->verificationId,
            'version' => $this->version,
            'organisation_identity_evidence_reference' => $this->organisationIdentityEvidenceReference,
            'representative_authority_evidence_reference' => $this->representativeAuthorityEvidenceReference,
            'event_organising_authority_evidence_reference' => $this->eventOrganisingAuthorityEvidenceReference,
            'verified_at' => $this->verifiedAt->format(DATE_ATOM),
            'valid_until' => $this->validUntil->format(DATE_ATOM),
        ];
    }
}
