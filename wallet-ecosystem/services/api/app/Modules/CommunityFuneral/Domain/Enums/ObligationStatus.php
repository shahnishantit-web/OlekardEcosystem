<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Enums;

enum ObligationStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case LateReview = 'late_review';
    case Refunded = 'refunded';
    case Failed = 'failed';
}
