<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Adapters;

use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Exceptions\InsufficientFundsException;
use App\Modules\Payment\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundRequest;
use App\Modules\Payment\Infrastructure\Adapters\WalletPaymentAdapter;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;

final class WalletPaymentAdapterTest extends TestCase
{
    /** @var MockObject&WalletModuleContract */
    private MockObject $wallet;

    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;

    /** @var MockObject&PaymentAttemptRepositoryInterface */
    private MockObject $attemptRepo;

    private WalletPaymentAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet      = $this->createMock(WalletModuleContract::class);
        $this->config      = $this->createMock(ConfigurationServiceInterface::class);
        $this->attemptRepo = $this->createMock(PaymentAttemptRepositoryInterface::class);

        $this->adapter = new WalletPaymentAdapter(
            wallet:            $this->wallet,
            config:            $this->config,
            audit:             $this->createMock(AuditServiceInterface::class),
            attemptRepository: $this->attemptRepo,
        );
    }

    public function test_get_provider_identifier(): void
    {
        self::assertSame('olekard_wallet', $this->adapter->getProviderIdentifier());
    }

    public function test_get_capabilities_has_correct_five_capabilities(): void
    {
        $caps = $this->adapter->getCapabilities();

        self::assertTrue($caps->has(ProviderCapabilityEnum::InitiatePayment));
        self::assertTrue($caps->has(ProviderCapabilityEnum::QueryTransactionStatus));
        self::assertTrue($caps->has(ProviderCapabilityEnum::ReversePriorPayment));
        self::assertFalse($caps->has(ProviderCapabilityEnum::QueryBalance));
        self::assertFalse($caps->has(ProviderCapabilityEnum::DeliverOtp));
    }

    public function test_ownership_verification_rejects_wrong_actor(): void
    {
        $walletId = (string) Uuid::uuid7();

        $this->wallet->method('ownerActorId')->willReturn('real-owner');
        $this->wallet->method('availableBalance')->willReturn(50000);
        $this->config->method('getOrDefault')->willReturn(100000);

        $this->expectException(\DomainException::class);

        $this->adapter->initiatePayment(new PaymentInitiateRequest(
            paymentIntentId:  (string) Uuid::uuid7(),
            paymentAttemptId: (string) Uuid::uuid7(),
            amountMinor:      10000,
            currencyCode:     'BWP',
            payerActorId:     'wrong-actor', // mismatch
            correlationId:    (string) Uuid::uuid7(),
            idempotencyKey:   (string) Uuid::uuid7(),
            walletId:         $walletId,
        ));
    }

    public function test_insufficient_funds_throws_exception(): void
    {
        $walletId = (string) Uuid::uuid7();
        $actorId  = (string) Uuid::uuid7();

        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('availableBalance')->willReturn(5000); // less than required
        $this->config->method('getOrDefault')->willReturn(100000);

        $this->expectException(InsufficientFundsException::class);

        $this->adapter->initiatePayment(new PaymentInitiateRequest(
            paymentIntentId:  (string) Uuid::uuid7(),
            paymentAttemptId: (string) Uuid::uuid7(),
            amountMinor:      10000, // more than available
            currencyCode:     'BWP',
            payerActorId:     $actorId,
            correlationId:    (string) Uuid::uuid7(),
            idempotencyKey:   (string) Uuid::uuid7(),
            walletId:         $walletId,
        ));
    }

    public function test_step_up_required_when_above_threshold(): void
    {
        $walletId = (string) Uuid::uuid7();
        $actorId  = (string) Uuid::uuid7();

        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('availableBalance')->willReturn(200000);
        $this->config->method('getOrDefault')->willReturn(100000); // threshold = 100000

        $this->expectException(StepUpRequiredException::class);

        $this->adapter->initiatePayment(new PaymentInitiateRequest(
            paymentIntentId:  (string) Uuid::uuid7(),
            paymentAttemptId: (string) Uuid::uuid7(),
            amountMinor:      150000, // above threshold, no step_up_token
            currencyCode:     'BWP',
            payerActorId:     $actorId,
            correlationId:    (string) Uuid::uuid7(),
            idempotencyKey:   (string) Uuid::uuid7(),
            walletId:         $walletId,
            stepUpToken:      null, // missing
        ));
    }

    public function test_hold_placed_with_correct_amount(): void
    {
        $walletId  = (string) Uuid::uuid7();
        $actorId   = (string) Uuid::uuid7();
        $holdId    = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();

        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('availableBalance')->willReturn(50000);
        $this->config->method('getOrDefault')->willReturn(100000); // threshold 100000 > amount
        $this->wallet->expects(self::once())
            ->method('createHold')
            ->with($walletId, 'BWP', 10000, $actorId, self::anything(), $attemptId)
            ->willReturn($holdId);

        $result = $this->adapter->initiatePayment(new PaymentInitiateRequest(
            paymentIntentId:  (string) Uuid::uuid7(),
            paymentAttemptId: $attemptId,
            amountMinor:      10000,
            currencyCode:     'BWP',
            payerActorId:     $actorId,
            correlationId:    (string) Uuid::uuid7(),
            idempotencyKey:   (string) Uuid::uuid7(),
            walletId:         $walletId,
        ));

        self::assertSame(PaymentAttemptStatus::Succeeded, $result->status);
        self::assertSame($holdId, $result->walletHoldId);
    }

    public function test_cancel_payment_returns_succeeded_for_missing_attempt(): void
    {
        $this->attemptRepo->method('findById')->willReturn(null);

        $result = $this->adapter->cancelPayment('nonexistent-attempt-id');

        self::assertTrue($result->cancelled);
    }

    public function test_query_payment_status_reads_db_only(): void
    {
        $attemptId = (string) Uuid::uuid7();

        $attempt = new PaymentAttemptModel();
        $attempt->id     = $attemptId;
        $attempt->status = PaymentAttemptStatus::Succeeded;

        $this->attemptRepo->method('findById')->willReturn($attempt);

        $result = $this->adapter->queryPaymentStatus($attemptId);

        self::assertSame(PaymentAttemptStatus::Succeeded, $result->status);
        self::assertSame($attemptId, $result->providerReferenceId);
    }

    public function test_initiate_refund_returns_succeeded(): void
    {
        $refundRequest = new PaymentRefundRequest(
            paymentIntentId:     (string) Uuid::uuid7(),
            paymentAttemptId:    (string) Uuid::uuid7(),
            refundId:            (string) Uuid::uuid7(),
            amountMinor:         5000,
            currencyCode:        'BWP',
            correlationId:       (string) Uuid::uuid7(),
            idempotencyKey:      (string) Uuid::uuid7(),
            providerReferenceId: null,
        );

        $result = $this->adapter->initiateRefund($refundRequest);

        self::assertSame(\App\Modules\Payment\Domain\Enums\PaymentRefundStatus::Succeeded, $result->status);
    }
}
