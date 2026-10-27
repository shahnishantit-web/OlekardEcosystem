<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use Illuminate\Support\Carbon;

final readonly class PayableBalance
{
    public function __construct(
        public string $fundReferenceId,
        public string $currencyCode,
        public int    $custodyBalanceMinor,
        public int    $confirmedRefundsMinor,
        public int    $openDisputeHoldsMinor,
        public int    $existingReservationsMinor,
        public Carbon $computedAt,
    ) {}

    public function payableMinor(): int
    {
        return max(
            0,
            $this->custodyBalanceMinor
                - $this->confirmedRefundsMinor
                - $this->openDisputeHoldsMinor
                - $this->existingReservationsMinor
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fund_reference_id'           => $this->fundReferenceId,
            'currency_code'               => $this->currencyCode,
            'custody_balance_minor'       => $this->custodyBalanceMinor,
            'confirmed_refunds_minor'     => $this->confirmedRefundsMinor,
            'open_dispute_holds_minor'    => $this->openDisputeHoldsMinor,
            'existing_reservations_minor' => $this->existingReservationsMinor,
            'payable_minor'               => $this->payableMinor(),
            'computed_at'                 => $this->computedAt->toIso8601String(),
        ];
    }
}
