<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PaymentExportRequestStatus: string
{
    case Queued     = 'queued';
    case Generating = 'generating';
    case Ready      = 'ready';
    case Failed     = 'failed';
    case Expired    = 'expired';

    public function isTerminal(): bool
    {
        return $this === self::Failed || $this === self::Expired;
    }

    public function isDownloadable(): bool
    {
        return $this === self::Ready;
    }
}
