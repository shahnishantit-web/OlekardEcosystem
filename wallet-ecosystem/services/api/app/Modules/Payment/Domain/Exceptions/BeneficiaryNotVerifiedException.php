<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use DomainException;

final class BeneficiaryNotVerifiedException extends DomainException
{
    public static function forBeneficiary(string $beneficiaryId): self
    {
        return new self(
            sprintf('Beneficiary "%s" is not in verified status.', $beneficiaryId)
        );
    }
}
