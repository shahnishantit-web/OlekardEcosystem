<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Enums;

enum SarStatus: string
{
    case Draft            = 'draft';
    case Approved         = 'approved';
    case Submitted        = 'submitted';
    case SubmissionFailed = 'submission_failed';
}
