<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Enums;

enum StaffRole: string
{
    case Manager = 'manager';
    case Reviewer = 'reviewer';
}
