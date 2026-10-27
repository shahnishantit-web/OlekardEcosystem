<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Enums;

enum RepresentativeStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Revoked = 'revoked';
}
