<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

use App\Modules\Events\Domain\ValueObjects\OrganiserVerificationEvidence;

/** Returns authoritative, purpose-specific onboarding evidence; a boolean or membership is insufficient. */
interface OrganiserVerificationContract
{
    public function evidenceForEventPublication(string $organisationId, string $eventId): OrganiserVerificationEvidence;
}
