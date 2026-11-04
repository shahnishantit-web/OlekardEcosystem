<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\ValueObjects\FinancialTermsApprovalEvidence;

/** Platform boundary to the authoritative Finance/Governance approval record. */
interface FinancialTermsApprovalEvidenceVerifier
{
    public function verify(string $reference, string $actorId, int $version, string $contentHash): FinancialTermsApprovalEvidence;
}
