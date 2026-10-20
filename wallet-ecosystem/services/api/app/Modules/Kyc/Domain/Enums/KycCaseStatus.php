<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Enums;

enum KycCaseStatus: string
{
    case Initiated           = 'initiated';
    case DocumentsSubmitted  = 'documents_submitted';
    case UnderReview         = 'under_review';
    case Approved            = 'approved';
    case Rejected            = 'rejected';
    case NeedsInfo           = 'needs_info';
    case Expired             = 'expired';

    public function isOpen(): bool
    {
        return match ($this) {
            self::Initiated,
            self::DocumentsSubmitted,
            self::UnderReview,
            self::NeedsInfo,
            self::Rejected  => true,
            default         => false,
        };
    }

    public function isEditable(): bool
    {
        return match ($this) {
            self::Initiated,
            self::DocumentsSubmitted => true,
            default                  => false,
        };
    }
}
