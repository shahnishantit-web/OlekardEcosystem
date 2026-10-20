<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObjects;

enum CredentialType: string
{
    case PIN               = 'pin';
    case PASSWORD          = 'password';
    case BIOMETRIC_BINDING = 'biometric_binding';
    case MFA_TOTP          = 'mfa_totp';
    case MFA_FIDO2         = 'mfa_fido2';
}
