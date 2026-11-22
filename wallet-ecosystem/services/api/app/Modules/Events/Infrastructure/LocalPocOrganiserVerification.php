<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\OrganiserVerificationContract;
use App\Modules\Events\Domain\ValueObjects\OrganiserVerificationEvidence;
use DateTimeImmutable;

/** Synthetic identity/authority proof; never valid outside an explicitly enabled local POC. */
final class LocalPocOrganiserVerification implements OrganiserVerificationContract
{
    public function evidenceForEventPublication(string $organisationId, string $eventId): OrganiserVerificationEvidence
    {
        abort_unless(app()->environment('local') && config('events.poc_enabled') === true,
            503, 'The local Events POC is disabled.');

        $now = new DateTimeImmutable('now');
        return new OrganiserVerificationEvidence(
            organisationId: $organisationId,
            eventId: $eventId,
            verificationId: 'local-poc-'.$eventId,
            version: 1,
            organisationIdentityEvidenceReference: 'poc://local/organisation/'.$organisationId,
            representativeAuthorityEvidenceReference: 'poc://local/representative/'.$organisationId,
            eventOrganisingAuthorityEvidenceReference: 'poc://local/event/'.$eventId,
            verifiedAt: $now,
            validUntil: $now->modify('+1 day'),
        );
    }
}
