<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Enums;

enum BatchStatus: string
{
    case Draft      = 'draft';
    case Processing = 'processing';
    case Matched    = 'matched';
    case Completed  = 'completed';
    case Failed     = 'failed';
}
