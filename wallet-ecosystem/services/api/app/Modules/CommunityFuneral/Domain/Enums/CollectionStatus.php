<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Enums;

enum CollectionStatus: string
{
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Active = 'active';
    case Closed = 'closed';
    case Archived = 'archived';
}
