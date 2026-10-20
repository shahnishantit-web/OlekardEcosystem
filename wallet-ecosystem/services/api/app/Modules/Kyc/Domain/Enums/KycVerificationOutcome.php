<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Enums;

enum KycVerificationOutcome: string
{
    case Pass                 = 'pass';
    case Fail                 = 'fail';
    case ManualReviewRequired = 'manual_review_required';
    case Error                = 'error';
}
