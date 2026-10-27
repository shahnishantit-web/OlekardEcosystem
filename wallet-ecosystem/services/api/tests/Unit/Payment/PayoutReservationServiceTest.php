<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Application\Services\PayoutReservationService;
use App\Modules\Payment\Domain\Contracts\PayoutRequestRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PayoutReservationRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PayoutRequestStatus;
use App\Modules\Payment\Domain\Enums\PayoutReservationStatus;
use App\Modules\Payment\Domain\Exceptions\PayoutReservationExpiredException;
use App\Modules\Payment\Domain\Exceptions\PayoutSelfApprovalException;
use App\Modules\Payment\Domain\ValueObjects\PayoutOperationId;
use App\Modules\Payment\Infrastructure\Models\PayoutRequestModel;
use App\Modules\Payment\Infrastructure\Models\PayoutReservationModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T044: PayoutReservationService unit tests.
 *
 * Tests self-approval guard, expiry check, invalidation logic, and expireStale.
 * Concurrent reservation (DB-gated) covered in PayoutConcurrentReservationTest (T061).
 */
final class PayoutReservationServiceTest extends TestCase
{
    private PayoutReservationRepositoryInterface&MockObject $reservationRepository;
    private PayoutRequestRepositoryInterface&MockObject     $requestRepository;
    private WalletModuleContract&MockObject                 $wallet;
    private OutboxEventPublisher&MockObject                 $outbox;
    private AuditServiceInterface&MockObject                $audit;
    private PayoutReservationService                        $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reservationRepository = $this->createMock(PayoutReservationRepositoryInterface::class);
        $this->requestRepository     = $this->createMock(PayoutRequestRepositoryInterface::class);
        $this->wallet                = $this->createMock(WalletModuleContract::class);
        $this->outbox                = $this->createMock(OutboxEventPublisher::class);
        $this->audit                 = $this->createMock(AuditServiceInterface::class);

        $this->service = new PayoutReservationService(
            $this->reservationRepository,
            $this->requestRepository,
            $this->wallet,
            $this->outbox,
            $this->audit,
        );
    }

    public function test_approve_reservation_throws_when_approver_is_maker(): void
    {
        $reservation           = new PayoutReservationModel();
        $reservation->maker_id = 'admin-001';
        $reservation->expires_at = CarbonImmutable::now()->addHours(24);

        $this->reservationRepository
            ->method('findByPayoutRequest')
            ->willReturn($reservation);

        $this->expectException(PayoutSelfApprovalException::class);

        $this->service->approveReservation('payout-001', 'admin-001');
    }

    public function test_approve_reservation_throws_when_reservation_is_expired(): void
    {
        $reservation           = new PayoutReservationModel();
        $reservation->maker_id = 'admin-001';
        $reservation->id       = 'res-001';
        $reservation->expires_at = CarbonImmutable::now()->subHours(1);

        $this->reservationRepository
            ->method('findByPayoutRequest')
            ->willReturn($reservation);

        $this->expectException(PayoutReservationExpiredException::class);

        $this->service->approveReservation('payout-001', 'admin-002');
    }

    public function test_approve_reservation_sets_status_to_approved(): void
    {
        $reservation                     = new PayoutReservationModel();
        $reservation->maker_id           = 'admin-001';
        $reservation->id                 = 'res-001';
        $reservation->payout_request_id  = 'payout-001';
        $reservation->reserved_amount_minor = 50_000;
        $reservation->currency_code      = 'BWP';
        $reservation->expires_at         = CarbonImmutable::now()->addHours(24);

        $payoutRequest             = new PayoutRequestModel();
        $payoutRequest->id         = 'payout-001';
        $payoutRequest->status     = PayoutRequestStatus::Reserved;

        $this->reservationRepository->method('findByPayoutRequest')->willReturn($reservation);
        $this->reservationRepository->method('save');
        $this->requestRepository->method('findById')->willReturn($payoutRequest);
        $this->requestRepository->method('save');
        $this->outbox->method('publish');
        $this->audit->method('record');

        $result = $this->service->approveReservation('payout-001', 'admin-002');

        $this->assertSame(PayoutReservationStatus::Approved, $result->status);
        $this->assertSame('admin-002', $result->approver_id);
    }

    public function test_reject_reservation_sets_status_to_rejected(): void
    {
        $reservation                 = new PayoutReservationModel();
        $reservation->id             = 'res-001';
        $reservation->payout_request_id = 'payout-001';

        $payoutRequest         = new PayoutRequestModel();
        $payoutRequest->status = PayoutRequestStatus::Reserved;

        $this->reservationRepository->method('findByPayoutRequest')->willReturn($reservation);
        $this->reservationRepository->method('save');
        $this->requestRepository->method('findById')->willReturn($payoutRequest);
        $this->requestRepository->method('save');
        $this->audit->method('record');

        $result = $this->service->rejectReservation('payout-001', 'Funds not available', 'admin-003');

        $this->assertSame(PayoutReservationStatus::Rejected, $result->status);
        $this->assertSame('Funds not available', $result->rejection_reason);
    }

    public function test_invalidate_if_changed_returns_false_when_hash_unchanged(): void
    {
        $beneficiaryId  = 'ben-001';
        $amountMinor    = 10_000;
        $expectedHash   = hash('sha256', "{$amountMinor}|{$beneficiaryId}");

        $reservation              = new PayoutReservationModel();
        $reservation->amount_hash = $expectedHash;
        $reservation->payout_request_id = 'payout-001';

        $this->reservationRepository->expects($this->never())->method('save');

        $result = $this->service->invalidateIfChanged($reservation, $amountMinor, $beneficiaryId);

        $this->assertFalse($result);
    }

    public function test_invalidate_if_changed_invalidates_when_amount_differs(): void
    {
        $beneficiaryId = 'ben-001';
        $originalHash  = hash('sha256', "10000|{$beneficiaryId}");

        $reservation                    = new PayoutReservationModel();
        $reservation->amount_hash       = $originalHash;
        $reservation->id                = 'res-001';
        $reservation->payout_request_id = 'payout-001';

        $payoutRequest         = new PayoutRequestModel();
        $payoutRequest->status = PayoutRequestStatus::Reserved;

        $this->reservationRepository->expects($this->once())->method('save');
        $this->requestRepository->method('findById')->willReturn($payoutRequest);
        $this->requestRepository->method('save');
        $this->audit->method('record');

        // Changed amount: 20_000 ≠ 10_000 → hash differs
        $result = $this->service->invalidateIfChanged($reservation, 20_000, $beneficiaryId);

        $this->assertTrue($result);
        $this->assertSame(PayoutReservationStatus::Invalidated, $reservation->status);
    }

    public function test_expire_stale_returns_zero_when_nothing_to_expire(): void
    {
        $this->reservationRepository
            ->method('findExpired')
            ->willReturn(collect());

        $count = $this->service->expireStale();

        $this->assertSame(0, $count);
    }

    public function test_expire_stale_marks_each_expired_reservation(): void
    {
        $res1                    = new PayoutReservationModel();
        $res1->id                = 'res-001';
        $res1->payout_request_id = 'payout-001';

        $res2                    = new PayoutReservationModel();
        $res2->id                = 'res-002';
        $res2->payout_request_id = 'payout-002';

        $payoutRequest1        = new PayoutRequestModel();
        $payoutRequest1->status = PayoutRequestStatus::Reserved;

        $payoutRequest2        = new PayoutRequestModel();
        $payoutRequest2->status = PayoutRequestStatus::Reserved;

        $this->reservationRepository
            ->method('findExpired')
            ->willReturn(collect([$res1, $res2]));

        $this->reservationRepository
            ->expects($this->exactly(2))
            ->method('save');

        $this->requestRepository
            ->method('findById')
            ->willReturnOnConsecutiveCalls($payoutRequest1, $payoutRequest2);

        $this->requestRepository
            ->expects($this->exactly(2))
            ->method('save');

        $this->audit
            ->expects($this->exactly(2))
            ->method('record');

        $count = $this->service->expireStale();

        $this->assertSame(2, $count);
        $this->assertSame(PayoutReservationStatus::Expired, $res1->status);
        $this->assertSame(PayoutReservationStatus::Expired, $res2->status);
    }

    public function test_payout_operation_id_is_deterministic_for_same_reservation(): void
    {
        $reservationId = 'res-abc-123';

        $id1 = PayoutOperationId::fromReservation($reservationId);
        $id2 = PayoutOperationId::fromReservation($reservationId);

        $this->assertSame($id1, $id2);
        $this->assertSame(64, strlen($id1));
    }
}
