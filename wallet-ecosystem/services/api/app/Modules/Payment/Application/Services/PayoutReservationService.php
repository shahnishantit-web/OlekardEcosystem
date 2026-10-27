<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PayoutRequestRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PayoutReservationRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PayoutRequestStatus;
use App\Modules\Payment\Domain\Enums\PayoutReservationStatus;
use App\Modules\Payment\Domain\Events\PayoutReservationApprovedEvent;
use App\Modules\Payment\Domain\Exceptions\InsufficientPayableBalanceException;
use App\Modules\Payment\Domain\Exceptions\PayoutReservationExpiredException;
use App\Modules\Payment\Domain\Exceptions\PayoutSelfApprovalException;
use App\Modules\Payment\Domain\ValueObjects\PayoutOperationId;
use App\Modules\Payment\Infrastructure\Models\PayoutRequestModel;
use App\Modules\Payment\Infrastructure\Models\PayoutReservationModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class PayoutReservationService
{
    public function __construct(
        private readonly PayoutReservationRepositoryInterface $reservationRepository,
        private readonly PayoutRequestRepositoryInterface     $requestRepository,
        private readonly WalletModuleContract                 $wallet,
        private readonly OutboxEventPublisher                 $outbox,
        private readonly AuditServiceInterface                $audit,
    ) {}

    public function createReservation(
        string $payoutRequestId,
        int    $reservedAmountMinor,
        string $makerId,
    ): PayoutReservationModel {
        return DB::connection('payment')->transaction(function () use ($payoutRequestId, $reservedAmountMinor, $makerId): PayoutReservationModel {
            // Pessimistic lock on the payout request row (FR-006)
            /** @var PayoutRequestModel|null $payoutRequest */
            $payoutRequest = PayoutRequestModel::query()
                ->lockForUpdate()
                ->find($payoutRequestId);

            if ($payoutRequest === null) {
                throw new \RuntimeException("PayoutRequest {$payoutRequestId} not found.");
            }

            // Recompute available balance inside the lock
            $custodyBalance = $this->wallet->availableBalance(
                $payoutRequest->custody_wallet_id,
                $payoutRequest->currency_code,
            ) ?? 0;

            $existingReservations = $this->requestRepository->sumActiveReservationsForFund(
                $payoutRequest->fund_reference_id,
                $payoutRequest->currency_code,
            );

            $payableBalance = max(0, $custodyBalance - $existingReservations);

            if ($reservedAmountMinor > $payableBalance) {
                throw InsufficientPayableBalanceException::forAmount($reservedAmountMinor, $payableBalance);
            }

            $expiryHours = (int) config('payment.payout.reservation_expiry_hours', 48);
            $amountHash  = hash('sha256', "{$reservedAmountMinor}|{$payoutRequest->beneficiary_id}");

            $reservation = new PayoutReservationModel([
                'id'                    => (string) Uuid::uuid7(),
                'payout_request_id'     => $payoutRequestId,
                'reserved_amount_minor' => $reservedAmountMinor,
                'currency_code'         => $payoutRequest->currency_code,
                'custody_wallet_id'     => $payoutRequest->custody_wallet_id,
                'status'                => PayoutReservationStatus::AwaitingApproval,
                'maker_id'              => $makerId,
                'expires_at'            => now()->addHours($expiryHours),
                'amount_hash'           => $amountHash,
            ]);

            $this->reservationRepository->save($reservation);

            $payoutRequest->status = PayoutRequestStatus::Reserved;
            $this->requestRepository->save($payoutRequest);

            $this->audit->record(new AuditEventData(
                action:       'payment.payout.reservation_created',
                actorType:    'admin',
                resourceType: 'payout_reservation',
                correlationId: $payoutRequestId,
                outcome:      'success',
                actorId:      $makerId,
                resourceId:   $reservation->id,
                afterState:   ['status' => PayoutReservationStatus::AwaitingApproval->value, 'reserved_amount_minor' => $reservedAmountMinor],
            ));

            return $reservation;
        });
    }

    public function approveReservation(string $payoutRequestId, string $approverId): PayoutReservationModel
    {
        $reservation = $this->reservationRepository->findByPayoutRequest($payoutRequestId);

        if ($reservation === null) {
            throw new \RuntimeException("No active reservation found for PayoutRequest {$payoutRequestId}.");
        }

        if ($approverId === $reservation->maker_id) {
            throw PayoutSelfApprovalException::notPermitted();
        }

        if ($reservation->expires_at->isPast()) {
            throw PayoutReservationExpiredException::forReservation($reservation->id);
        }

        $reservation->status      = PayoutReservationStatus::Approved;
        $reservation->approver_id = $approverId;
        $reservation->approved_at = now();

        $this->reservationRepository->save($reservation);

        $payoutRequest = $this->requestRepository->findById($payoutRequestId);

        if ($payoutRequest !== null) {
            $payoutRequest->status       = PayoutRequestStatus::Approved;
            $payoutRequest->operation_id = PayoutOperationId::fromReservation($reservation->id);
            $this->requestRepository->save($payoutRequest);
        }

        $this->outbox->publish(new PayoutReservationApprovedEvent(
            payoutRequestId:     $payoutRequestId,
            reservationId:       $reservation->id,
            approvedBy:          $approverId,
            reservedAmountMinor: $reservation->reserved_amount_minor,
            currencyCode:        $reservation->currency_code,
            occurredAt:          now()->toIso8601String(),
        ));

        $this->audit->record(new AuditEventData(
            action:       'payment.payout.reservation_approved',
            actorType:    'admin',
            resourceType: 'payout_reservation',
            correlationId: $payoutRequestId,
            outcome:      'success',
            actorId:      $approverId,
            resourceId:   $reservation->id,
            afterState:   ['status' => PayoutReservationStatus::Approved->value],
        ));

        return $reservation;
    }

    public function rejectReservation(
        string $payoutRequestId,
        string $rejectionReason,
        string $rejectorId,
    ): PayoutReservationModel {
        $reservation = $this->reservationRepository->findByPayoutRequest($payoutRequestId);

        if ($reservation === null) {
            throw new \RuntimeException("No active reservation found for PayoutRequest {$payoutRequestId}.");
        }

        $reservation->status           = PayoutReservationStatus::Rejected;
        $reservation->rejection_reason = $rejectionReason;

        $this->reservationRepository->save($reservation);

        $payoutRequest = $this->requestRepository->findById($payoutRequestId);

        if ($payoutRequest !== null) {
            $payoutRequest->status = PayoutRequestStatus::AwaitingApproval;
            $this->requestRepository->save($payoutRequest);
        }

        $this->audit->record(new AuditEventData(
            action:       'payment.payout.reservation_rejected',
            actorType:    'admin',
            resourceType: 'payout_reservation',
            correlationId: $payoutRequestId,
            outcome:      'success',
            actorId:      $rejectorId,
            resourceId:   $reservation->id,
            afterState:   ['status' => PayoutReservationStatus::Rejected->value],
        ));

        return $reservation;
    }

    public function invalidateIfChanged(
        PayoutReservationModel $reservation,
        int    $newAmountMinor,
        string $newBeneficiaryId,
    ): bool {
        $newHash = hash('sha256', "{$newAmountMinor}|{$newBeneficiaryId}");

        if ($newHash === $reservation->amount_hash) {
            return false;
        }

        $reservation->status      = PayoutReservationStatus::Invalidated;
        $reservation->approver_id = null;
        $reservation->approved_at = null;

        $this->reservationRepository->save($reservation);

        $payoutRequest = $this->requestRepository->findById($reservation->payout_request_id);

        if ($payoutRequest !== null) {
            $payoutRequest->status = PayoutRequestStatus::AwaitingApproval;
            $this->requestRepository->save($payoutRequest);
        }

        $this->audit->record(new AuditEventData(
            action:       'payment.payout.reservation_invalidated',
            actorType:    'system',
            resourceType: 'payout_reservation',
            correlationId: $reservation->payout_request_id,
            outcome:      'success',
            resourceId:   $reservation->id,
            afterState:   ['status' => PayoutReservationStatus::Invalidated->value, 'reason' => 'amount_or_destination_changed'],
        ));

        return true;
    }

    public function expireStale(): int
    {
        $expired = $this->reservationRepository->findExpired();
        $count   = 0;

        foreach ($expired as $reservation) {
            $reservation->status = PayoutReservationStatus::Expired;
            $this->reservationRepository->save($reservation);

            $payoutRequest = $this->requestRepository->findById($reservation->payout_request_id);

            if ($payoutRequest !== null && $payoutRequest->status === PayoutRequestStatus::Reserved) {
                $payoutRequest->status = PayoutRequestStatus::AwaitingApproval;
                $this->requestRepository->save($payoutRequest);
            }

            $this->audit->record(new AuditEventData(
                action:       'payment.payout.reservation_expired',
                actorType:    'system',
                resourceType: 'payout_reservation',
                correlationId: $reservation->payout_request_id,
                outcome:      'success',
                resourceId:   $reservation->id,
                afterState:   ['status' => PayoutReservationStatus::Expired->value],
            ));

            $count++;
        }

        return $count;
    }
}
