<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Commands;

final class ResolveAlertCommand
{
    public function __construct(
        public readonly string  $alertId,
        public readonly string  $decision,
        public readonly string  $rationale,
        public readonly int     $version,
        public readonly string  $decidedBy,
        public readonly ?string $checkerId   = null,
        // SAR-specific fields (required when decision=file_sar)
        public readonly ?string $sarNarrative             = null,
        public readonly ?array  $sarSuspectedOffences     = null,
        public readonly ?array  $sarTransactionReferences = null,
        public readonly ?string $sarAmountInvolved        = null,
        public readonly ?string $sarCurrency              = null,
        public readonly ?string $sarPeriodStart           = null,
        public readonly ?string $sarPeriodEnd             = null,
    ) {}
}
