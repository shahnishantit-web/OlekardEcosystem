<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Enums;

enum KycDocumentType: string
{
    case NationalId      = 'national_id';
    case Passport        = 'passport';
    case DriversLicence  = 'drivers_licence';
    case UtilityBill     = 'utility_bill';
    case Selfie          = 'selfie';
    case Other           = 'other';

    public function requiresExpiry(): bool
    {
        return match ($this) {
            self::UtilityBill, self::Selfie, self::Other => false,
            default                                       => true,
        };
    }

    public function isIdentityDocument(): bool
    {
        return match ($this) {
            self::NationalId,
            self::Passport,
            self::DriversLicence => true,
            default              => false,
        };
    }
}
