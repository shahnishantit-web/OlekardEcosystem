<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObjects;

enum OtpChallengeType: string
{
    case REGISTRATION       = 'registration';
    case LOGIN              = 'login';
    case DEVICE_VERIFICATION = 'device_verification';
    case PIN_RESET          = 'pin_reset';
    case PIN_UNBLOCK        = 'pin_unblock';
    case MFA_ENROLLMENT     = 'mfa_enrollment';
    case STEP_UP            = 'step_up';
    // E02 extensions — contact change challenges (ADR-E02-001)
    case EMAIL_CHANGE       = 'email_change';
    case MOBILE_CHANGE      = 'mobile_change';
}
