<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\ValueObjects;

final readonly class TransactionRiskContext
{
    public function __construct(
        public string  $idempotencyKey,
        public string  $transactionReference,
        public string  $transactionType,
        public string  $amount,
        public string  $currency,
        public string  $customerId,
        public ?string $walletId       = null,
        public ?string $agentId        = null,
        public ?string $beneficiaryId  = null,
        public ?string $deviceId       = null,
        public ?string $ipAddress      = null,
        public ?string $kycTier        = null,
        public bool    $isNewDevice     = false,
        public bool    $isNewBeneficiary = false,
    ) {}
}
