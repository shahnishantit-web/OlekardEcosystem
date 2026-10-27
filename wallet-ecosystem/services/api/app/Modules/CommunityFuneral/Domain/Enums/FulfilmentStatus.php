<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Enums;

enum FulfilmentStatus: string
{
    case Pending = 'pending';
    case Fulfilled = 'fulfilled';
    case HeldForReview = 'held_for_review';
}
