<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class EnrichedPayableObligation
{
    public function __construct(
        public int                   $amountMinor,
        public string                $currencyCode,
        public string                $organisationId,
        public string                $payerActorId,
        public ?\DateTimeImmutable   $expiresAt             = null,
        public ?string               $holdReference         = null,
        public array                 $beneficiaryAllocations = [],
        public array                 $metadata              = [],
    ) {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException(
                "EnrichedPayableObligation amount_minor must be a positive integer, got [{$amountMinor}]."
            );
        }
    }
}
