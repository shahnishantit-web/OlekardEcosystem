<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Events;

use App\Modules\Events\Domain\ValueObjects\OrganiserVerificationEvidence;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class OrganiserVerificationEvidenceTest extends TestCase
{
    public function test_purpose_specific_evidence_requires_identity_representative_and_event_authority(): void
    {
        $evidence = new OrganiserVerificationEvidence(
            organisationId: 'org-1', eventId: 'event-1', verificationId: 'verification-1', version: 2,
            organisationIdentityEvidenceReference: 'identity-evidence-1',
            representativeAuthorityEvidenceReference: 'representative-evidence-1',
            eventOrganisingAuthorityEvidenceReference: 'event-authority-evidence-1',
            verifiedAt: new \DateTimeImmutable('2026-11-04T10:00:00Z'),
            validUntil: new \DateTimeImmutable('2026-11-05T10:00:00Z'),
        );

        self::assertSame('identity-evidence-1', $evidence->toSnapshot()['organisation_identity_evidence_reference']);
        self::assertSame('representative-evidence-1', $evidence->toSnapshot()['representative_authority_evidence_reference']);
        self::assertSame('event-authority-evidence-1', $evidence->toSnapshot()['event_organising_authority_evidence_reference']);
    }

    public function test_purpose_specific_evidence_rejects_missing_authority_proof(): void
    {
        $this->expectException(ValidationException::class);
        new OrganiserVerificationEvidence(
            organisationId: 'org-1', eventId: 'event-1', verificationId: 'verification-1', version: 2,
            organisationIdentityEvidenceReference: 'identity-evidence-1',
            representativeAuthorityEvidenceReference: 'representative-evidence-1',
            eventOrganisingAuthorityEvidenceReference: '',
            verifiedAt: new \DateTimeImmutable('2026-11-04T10:00:00Z'),
            validUntil: new \DateTimeImmutable('2026-11-05T10:00:00Z'),
        );
    }
}
