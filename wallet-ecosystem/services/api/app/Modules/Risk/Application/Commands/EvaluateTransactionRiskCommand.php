<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Commands;

use App\Modules\Risk\Domain\ValueObjects\TransactionRiskContext;

final readonly class EvaluateTransactionRiskCommand
{
    public function __construct(
        public string  $idempotencyKey,
        public string  $transactionReference,
        public string  $transactionType,
        public string  $amount,
        public string  $currency,
        public string  $customerId,
        public ?string $walletId          = null,
        public ?string $agentId           = null,
        public ?string $beneficiaryId     = null,
        public ?string $deviceId          = null,
        public ?string $ipAddress         = null,
        public ?string $kycTier           = null,
        public bool    $isNewDevice        = false,
        public bool    $isNewBeneficiary   = false,
    ) {}

    public function toContext(): TransactionRiskContext
    {
        return new TransactionRiskContext(
            idempotencyKey:      $this->idempotencyKey,
            transactionReference: $this->transactionReference,
            transactionType:     $this->transactionType,
            amount:              $this->amount,
            currency:            $this->currency,
            customerId:          $this->customerId,
            walletId:            $this->walletId,
            agentId:             $this->agentId,
            beneficiaryId:       $this->beneficiaryId,
            deviceId:            $this->deviceId,
            ipAddress:           $this->ipAddress,
            kycTier:             $this->kycTier,
            isNewDevice:         $this->isNewDevice,
            isNewBeneficiary:    $this->isNewBeneficiary,
        );
    }
}
