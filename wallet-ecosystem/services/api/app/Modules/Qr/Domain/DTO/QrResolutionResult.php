<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\DTO;

use App\Modules\Qr\Domain\Enums\QrCodeType;
use Carbon\Carbon;

final readonly class QrResolutionResult
{
    public function __construct(
        public string     $qrCodeId,
        public QrCodeType $type,
        public string     $merchantId,
        public string     $merchantDisplayName,
        public ?string    $merchantCategory,
        public string     $currency,
        public ?int       $amount,
        public bool       $amountRequired,
        public ?Carbon    $expiresAt,
    ) {}
}
