<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\OrganiserVerificationContract;
use App\Modules\Events\Domain\ValueObjects\OrganiserVerificationEvidence;
use Illuminate\Validation\ValidationException;

/** Production fails closed until onboarding supplies purpose-specific evidence. */
final class DenyOrganiserVerification implements OrganiserVerificationContract
{
    public function evidenceForEventPublication(string $organisationId, string $eventId): OrganiserVerificationEvidence
    {
        throw ValidationException::withMessages([
            'organiser_verification' => 'Authoritative organiser verification for event publication is not configured.',
        ]);
    }
}
