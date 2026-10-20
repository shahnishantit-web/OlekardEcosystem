<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Contracts;

use App\Modules\Kyc\Domain\ValueObjects\VerificationOutcome;
use App\Modules\Kyc\Infrastructure\Models\KycCaseModel;

interface KycVerificationProvider
{
    public function verify(KycCaseModel $case): VerificationOutcome;

    public function providerName(): string;
}
