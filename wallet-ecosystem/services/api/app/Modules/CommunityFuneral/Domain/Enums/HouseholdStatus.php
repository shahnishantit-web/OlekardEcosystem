<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Enums;

enum HouseholdStatus: string
{
    case PendingReview = 'pending_review';
    case Eligible = 'eligible';
    case Exempt = 'exempt';
    case Suspended = 'suspended';
}
