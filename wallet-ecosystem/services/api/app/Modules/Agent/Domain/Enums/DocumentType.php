<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Enums;

enum DocumentType: string
{
    case NationalId           = 'national_id';
    case ProofOfAddress       = 'proof_of_address';
    case BusinessRegistration = 'business_registration';
}
