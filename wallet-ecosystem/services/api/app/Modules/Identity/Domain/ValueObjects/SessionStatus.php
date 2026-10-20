<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObjects;

enum SessionStatus: string
{
    case ACTIVE       = 'active';
    case MFA_PENDING  = 'mfa_pending';
    case STEP_UP_PENDING = 'step_up_pending';
    case EXPIRED      = 'expired';
    case REVOKED      = 'revoked';
    case LOGGED_OUT   = 'logged_out';
}
