<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\DTO;

use App\Modules\Qr\Domain\Enums\QrPaymentStatus;
use Carbon\Carbon;

final readonly class QrPaymentResult
{
    public function __construct(
        public string           $id,
        public QrPaymentStatus  $status,
        public int              $amount,
        public int              $fee,
        public int              $totalDebited,
        public string           $currency,
        public string           $merchantDisplayName,
        public ?string          $receiptRef,
        public ?Carbon          $createdAt,
    ) {}
}
