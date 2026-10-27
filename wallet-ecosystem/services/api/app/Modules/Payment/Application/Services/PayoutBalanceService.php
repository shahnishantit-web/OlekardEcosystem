<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PayoutRequestRepositoryInterface;
use App\Modules\Payment\Domain\ValueObjects\PayableBalance;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;

final class PayoutBalanceService
{
    public function __construct(
        private readonly WalletModuleContract             $wallet,
        private readonly PayoutRequestRepositoryInterface $requestRepository,
    ) {}

    public function computeForFund(
        string $fundReferenceId,
        string $purposeType,
        string $currencyCode,
        string $custodyWalletId,
    ): PayableBalance {
        $custodyBalance = $this->wallet->availableBalance($custodyWalletId, $currencyCode) ?? 0;

        $confirmedRefunds = (int) DB::connection('payment')
            ->table('payment_refunds')
            ->join('payment_intents', 'payment_refunds.payment_intent_id', '=', 'payment_intents.id')
            ->where('payment_intents.purpose_reference', $fundReferenceId)
            ->where('payment_intents.purpose_type', $purposeType)
            ->where('payment_refunds.currency_code', $currencyCode)
            ->whereIn('payment_refunds.status', ['confirmed', 'settled'])
            ->sum('payment_refunds.amount_minor');

        $openDisputeHolds = (int) DB::connection('payment')
            ->table('payment_dispute_holds as pdh')
            ->join('clearing_records as cr', 'pdh.clearing_record_id', '=', 'cr.id')
            ->join('payment_attempts as pa', 'cr.payment_attempt_id', '=', 'pa.id')
            ->join('payment_intents as pi', 'pa.payment_intent_id', '=', 'pi.id')
            ->where('pi.purpose_reference', $fundReferenceId)
            ->where('pi.purpose_type', $purposeType)
            ->whereIn('pdh.status', ['open', 'under_review', 'awaiting_evidence'])
            ->sum('pdh.held_amount_minor');

        $existingReservations = $this->requestRepository->sumActiveReservationsForFund(
            $fundReferenceId,
            $currencyCode,
        );

        return new PayableBalance(
            fundReferenceId:           $fundReferenceId,
            currencyCode:              $currencyCode,
            custodyBalanceMinor:       $custodyBalance,
            confirmedRefundsMinor:     $confirmedRefunds,
            openDisputeHoldsMinor:     $openDisputeHolds,
            existingReservationsMinor: $existingReservations,
            computedAt:                now(),
        );
    }
}
