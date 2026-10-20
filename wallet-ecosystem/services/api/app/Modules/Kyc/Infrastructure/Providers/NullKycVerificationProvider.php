<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Providers;

use App\Modules\Kyc\Domain\Contracts\KycVerificationProvider;
use App\Modules\Kyc\Domain\Enums\KycVerificationOutcome;
use App\Modules\Kyc\Domain\ValueObjects\VerificationOutcome;
use App\Modules\Kyc\Infrastructure\Models\KycCaseModel;

final class NullKycVerificationProvider implements KycVerificationProvider
{
    public function verify(KycCaseModel $case): VerificationOutcome
    {
        // TBD-E05-001: real provider binding deferred until Product/Legal decision
        return new VerificationOutcome(
            outcome:           KycVerificationOutcome::ManualReviewRequired,
            providerReference: 'null-provider',
            rawResponse:       [],
            confidenceScore:   null,
        );
    }

    public function providerName(): string
    {
        return 'null-provider';
    }
}
