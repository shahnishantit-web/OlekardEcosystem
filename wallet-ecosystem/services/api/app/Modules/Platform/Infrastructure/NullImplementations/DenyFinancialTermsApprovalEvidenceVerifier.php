<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\NullImplementations;

use App\Modules\Platform\Domain\Contracts\FinancialTermsApprovalEvidenceVerifier;
use App\Modules\Platform\Domain\ValueObjects\FinancialTermsApprovalEvidence;

final class DenyFinancialTermsApprovalEvidenceVerifier implements FinancialTermsApprovalEvidenceVerifier
{
    public function verify(string $reference, string $actorId, int $version, string $contentHash): FinancialTermsApprovalEvidence
    {
        throw new \DomainException('Authoritative Finance/Governance terms approval evidence is not configured.');
    }
}
