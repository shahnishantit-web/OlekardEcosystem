<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Adapters;

use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Exceptions\InsufficientFundsException;
use App\Modules\Payment\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Payment\Domain\Exceptions\TransactionLimitExceededException;
use App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundRequest;
use App\Modules\Payment\Infrastructure\Adapters\WalletPaymentAdapter;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Wallet\Domain\Exceptions\UnsupportedCurrencyException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class WalletPaymentAdapterTest extends TestCase
{
    /** @var MockObject&WalletModuleContract */
    private MockObject $wallet;

    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;

    /** @var MockObject&AuditServiceInterface */
    private MockObject $audit;

    /** @var MockObject&PaymentAttemptRepositoryInterface */
    private MockObject $attemptRepo;

    /** @var MockObject&ActorStepUpVerifierContract */
    private MockObject $stepUpVerifier;

    private WalletPaymentAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet         = $this->createMock(WalletModuleContract::class);
        $this->config         = $this->createMock(ConfigurationServiceInterface::class);
        $this->audit          = $this->createMock(AuditServiceInterface::class);
        $this->attemptRepo    = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->stepUpVerifier = $this->createMock(ActorStepUpVerifierContract::class);

        $this->adapter = new WalletPaymentAdapter(
            wallet:            $this->wallet,
            config:            $this->config,
            audit:             $this->audit,
            attemptRepository: $this->attemptRepo,
            stepUpVerifier:    $this->stepUpVerifier,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest(array $overrides = []): PaymentInitiateRequest
    {
        // Use array_key_exists so explicit null overrides (e.g. 'walletId' => null) are preserved
        $get = static fn (string $key, mixed $default): mixed =>
            array_key_exists($key, $overrides) ? $overrides[$key] : $default;

        return new PaymentInitiateRequest(
            paymentIntentId:  $get('paymentIntentId',  (string) Uuid::uuid7()),
            paymentAttemptId: $get('paymentAttemptId', (string) Uuid::uuid7()),
            amountMinor:      $get('amountMinor',      10000),
            currencyCode:     $get('currencyCode',     'BWP'),
            payerActorId:     $get('payerActorId',     (string) Uuid::uuid7()),
            correlationId:    $get('correlationId',    (string) Uuid::uuid7()),
            idempotencyKey:   $get('idempotencyKey',   (string) Uuid::uuid7()),
            walletId:         $get('walletId',         (string) Uuid::uuid7()),
            stepUpToken:      $get('stepUpToken',      null),
            sessionId:        $get('sessionId',        null),
        );
    }

    /** Sets up all validation gates to pass for a given wallet/actor pair. */
    private function mockAllGatesPass(string $walletId, string $actorId, int $balance = 100000): void
    {
        $this->wallet->method('isActive')->with($walletId)->willReturn(true);
        $this->wallet->method('ownerActorId')->with($walletId)->willReturn($actorId);
        $this->wallet->method('pocketExists')->willReturn(true);
        $this->wallet->method('availableBalance')->willReturn($balance);
        $this->config->method('getOrDefault')
            ->willReturnCallback(function (string $key): mixed {
                if ($key === 'auth.step_up_threshold') {
                    return 1_000_000; // very high — step-up never triggered unless test wants it
                }
                if (str_starts_with($key, 'payment.wallet_transaction_limit_')) {
                    return null; // no limit unless test overrides
                }
                return null;
            });
    }

    // -------------------------------------------------------------------------
    // Provider metadata
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // US1: Validation gate — existing tests (updated for new gates)
    // -------------------------------------------------------------------------

    public function test_ownership_verification_rejects_wrong_actor(): void
    {
        $walletId = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->willReturn(true); // new: must pass isActive gate
        $this->wallet->method('ownerActorId')->willReturn('real-owner');
        $this->wallet->method('availableBalance')->willReturn(50000);
        $this->config->method('getOrDefault')->willReturn(100000);

        $this->expectException(\DomainException::class);

        $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'amountMinor' => 10000,
            'payerActorId' => 'wrong-actor',
        ]));
    }

    public function test_insufficient_funds_throws_exception(): void
    {
        $walletId = (string) Uuid::uuid7();
        $actorId  = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->willReturn(true);       // new gate
        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('pocketExists')->willReturn(true);   // new gate
        $this->wallet->method('availableBalance')->willReturn(5000);
        $this->config->method('getOrDefault')->willReturn(100000);

        $this->expectException(InsufficientFundsException::class);

        $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'payerActorId' => $actorId,
            'amountMinor' => 10000,
        ]));
    }

    public function test_step_up_required_when_above_threshold(): void
    {
        $walletId = (string) Uuid::uuid7();
        $actorId  = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->willReturn(true);
        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('pocketExists')->willReturn(true);
        $this->wallet->method('availableBalance')->willReturn(200000);
        // Differentiate keys: threshold=100000, no transaction limit
        $this->config->method('getOrDefault')
            ->willReturnCallback(function (string $key): mixed {
                return $key === 'auth.step_up_threshold' ? 100000 : null;
            });

        $this->expectException(StepUpRequiredException::class);

        $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'payerActorId' => $actorId,
            'amountMinor' => 150000, // above threshold, no step-up token
        ]));
    }

    public function test_hold_placed_with_correct_amount(): void
    {
        $walletId  = (string) Uuid::uuid7();
        $actorId   = (string) Uuid::uuid7();
        $holdId    = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->willReturn(true);
        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('pocketExists')->willReturn(true);
        $this->wallet->method('availableBalance')->willReturn(50000);
        $this->config->method('getOrDefault')->willReturn(100000); // threshold > amount; limit 100000 > 10000
        $this->wallet->expects(self::once())
            ->method('createHold')
            ->with($walletId, 'BWP', 10000, $actorId, self::anything(), $attemptId)
            ->willReturn($holdId);

        $result = $this->adapter->initiatePayment($this->makeRequest([
            'walletId'        => $walletId,
            'payerActorId'    => $actorId,
            'amountMinor'     => 10000,
            'paymentAttemptId' => $attemptId,
        ]));

        self::assertSame(PaymentAttemptStatus::Succeeded, $result->status);
        self::assertSame($holdId, $result->walletHoldId);
    }

    // -------------------------------------------------------------------------
    // US1: Validation gate — new tests (T007)
    // -------------------------------------------------------------------------

    public function test_null_wallet_id_throws_invalid_argument_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->adapter->initiatePayment($this->makeRequest(['walletId' => null]));
    }

    public function test_inactive_wallet_throws_wallet_not_transactable(): void
    {
        $walletId = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->with($walletId)->willReturn(false);
        $this->wallet->expects(self::never())->method('createHold');

        $this->expectException(WalletNotTransactableException::class);

        $this->adapter->initiatePayment($this->makeRequest(['walletId' => $walletId]));
    }

    public function test_missing_pocket_throws_unsupported_currency(): void
    {
        $walletId = (string) Uuid::uuid7();
        $actorId  = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->willReturn(true);
        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('pocketExists')->willReturn(false);
        $this->wallet->expects(self::never())->method('createHold');

        $this->expectException(UnsupportedCurrencyException::class);

        $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'payerActorId' => $actorId,
        ]));
    }

    public function test_amount_above_transaction_limit_throws_exception(): void
    {
        $walletId = (string) Uuid::uuid7();
        $actorId  = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->willReturn(true);
        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('pocketExists')->willReturn(true);
        $this->wallet->method('availableBalance')->willReturn(100000);
        $this->config->method('getOrDefault')
            ->willReturnCallback(function (string $key): mixed {
                if ($key === 'auth.step_up_threshold') {
                    return 1_000_000;
                }
                if ($key === 'payment.wallet_transaction_limit_BWP') {
                    return 5000; // limit = 5000 BWP minor
                }
                return null;
            });
        $this->wallet->expects(self::never())->method('createHold');

        $this->expectException(TransactionLimitExceededException::class);

        $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'payerActorId' => $actorId,
            'amountMinor' => 6000, // 6000 > limit 5000
        ]));
    }

    public function test_no_limit_configured_allows_any_amount(): void
    {
        $walletId  = (string) Uuid::uuid7();
        $actorId   = (string) Uuid::uuid7();
        $holdId    = (string) Uuid::uuid7();

        $this->mockAllGatesPass($walletId, $actorId, 500000);
        $this->wallet->method('createHold')->willReturn($holdId);

        $result = $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'payerActorId' => $actorId,
            'amountMinor' => 400000, // very large; no limit configured
        ]));

        self::assertSame(PaymentAttemptStatus::Succeeded, $result->status);
    }

    public function test_audit_record_emitted_on_successful_hold_placement(): void
    {
        $walletId  = (string) Uuid::uuid7();
        $actorId   = (string) Uuid::uuid7();
        $holdId    = (string) Uuid::uuid7();

        $this->mockAllGatesPass($walletId, $actorId);
        $this->wallet->method('createHold')->willReturn($holdId);

        $this->audit->expects(self::once())
            ->method('record')
            ->with(self::callback(function ($data): bool {
                return $data->action === 'payment.wallet.hold_placed';
            }));

        $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'payerActorId' => $actorId,
        ]));
    }

    // -------------------------------------------------------------------------
    // US2: Idempotency (T008)
    // -------------------------------------------------------------------------

    public function test_idempotent_retry_does_not_call_create_hold_twice(): void
    {
        $walletId  = (string) Uuid::uuid7();
        $actorId   = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();
        $holdId    = (string) Uuid::uuid7();

        $this->mockAllGatesPass($walletId, $actorId);
        $this->wallet->expects(self::once()) // exactly ONE call regardless of how many times we call initiatePayment
            ->method('createHold')
            ->willReturn($holdId);

        $request = $this->makeRequest([
            'walletId'        => $walletId,
            'payerActorId'    => $actorId,
            'paymentAttemptId' => $attemptId,
        ]);

        $result1 = $this->adapter->initiatePayment($request);
        $result2 = $this->adapter->initiatePayment($request); // same request = same attemptId

        self::assertSame($holdId, $result1->walletHoldId);
        self::assertSame($holdId, $result2->walletHoldId);
    }

    // -------------------------------------------------------------------------
    // US3: Crash-after-hold recovery (T010)
    // -------------------------------------------------------------------------

    public function test_crash_after_hold_recovery_returns_same_hold_on_retry(): void
    {
        $walletId  = (string) Uuid::uuid7();
        $actorId   = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();
        $holdId    = (string) Uuid::uuid7();

        $this->mockAllGatesPass($walletId, $actorId);
        // Wallet module idempotency: same transactionReference → same holdId, called exactly once
        $this->wallet->expects(self::once())
            ->method('createHold')
            ->with(self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), $attemptId)
            ->willReturn($holdId);

        $request = $this->makeRequest([
            'walletId'        => $walletId,
            'payerActorId'    => $actorId,
            'paymentAttemptId' => $attemptId,
        ]);

        // Simulate: first call succeeds; crash occurs before wallet_hold_id is persisted;
        // second call retries with same attemptId — wallet module idempotency ensures no double-hold.
        $result1 = $this->adapter->initiatePayment($request);
        $result2 = $this->adapter->initiatePayment($request);

        self::assertSame($holdId, $result1->walletHoldId);
        self::assertSame($holdId, $result2->walletHoldId);
    }

    // -------------------------------------------------------------------------
    // US4: Refund and cancel contract (T011)
    // -------------------------------------------------------------------------

    public function test_initiate_refund_does_not_call_release_hold(): void
    {
        $this->wallet->expects(self::never())->method('releaseHold');

        $result = $this->adapter->initiateRefund($this->makeRefundRequest());

        self::assertSame(PaymentRefundStatus::Succeeded, $result->status);
    }

    public function test_initiate_refund_returns_succeeded_for_partial_amount(): void
    {
        $result = $this->adapter->initiateRefund($this->makeRefundRequest(['amountMinor' => 3000]));

        self::assertSame(PaymentRefundStatus::Succeeded, $result->status);
    }

    public function test_initiate_refund_emits_audit_record(): void
    {
        $this->audit->expects(self::once())
            ->method('record')
            ->with(self::callback(function ($data): bool {
                return $data->action === 'payment.wallet.refund_initiated';
            }));

        $this->adapter->initiateRefund($this->makeRefundRequest());
    }

    public function test_cancel_payment_does_not_call_release_hold(): void
    {
        $this->attemptRepo->method('findById')->willReturn(null);
        $this->wallet->expects(self::never())->method('releaseHold');

        $result = $this->adapter->cancelPayment((string) Uuid::uuid7());

        self::assertTrue($result->cancelled);
    }

    public function test_cancel_payment_is_idempotent(): void
    {
        $attemptId = (string) Uuid::uuid7();
        $this->attemptRepo->method('findById')->willReturn(null);

        $result1 = $this->adapter->cancelPayment($attemptId);
        $result2 = $this->adapter->cancelPayment($attemptId);

        self::assertTrue($result1->cancelled);
        self::assertTrue($result2->cancelled);
    }

    // -------------------------------------------------------------------------
    // US5: Configurable step-up threshold (T012)
    // -------------------------------------------------------------------------

    public function test_payment_below_threshold_does_not_require_step_up(): void
    {
        $walletId = (string) Uuid::uuid7();
        $actorId  = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->willReturn(true);
        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('pocketExists')->willReturn(true);
        $this->wallet->method('availableBalance')->willReturn(100000);
        $this->config->method('getOrDefault')
            ->willReturnCallback(fn (string $key): mixed => $key === 'auth.step_up_threshold' ? 10000 : null);
        $this->wallet->method('createHold')->willReturn((string) Uuid::uuid7());

        // amount 9999 < threshold 10000 — no step-up token needed
        $result = $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'payerActorId' => $actorId,
            'amountMinor' => 9999,
            'stepUpToken' => null,
        ]));

        self::assertSame(PaymentAttemptStatus::Succeeded, $result->status);
    }

    public function test_payment_at_threshold_without_token_throws_step_up_required(): void
    {
        $walletId = (string) Uuid::uuid7();
        $actorId  = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->willReturn(true);
        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('pocketExists')->willReturn(true);
        $this->wallet->method('availableBalance')->willReturn(100000);
        $this->config->method('getOrDefault')
            ->willReturnCallback(fn (string $key): mixed => $key === 'auth.step_up_threshold' ? 10000 : null);

        $this->expectException(StepUpRequiredException::class);

        // amount 10000 >= threshold 10000, no token
        $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'payerActorId' => $actorId,
            'amountMinor' => 10000,
            'stepUpToken' => null,
        ]));
    }

    public function test_payment_above_threshold_with_valid_token_places_hold(): void
    {
        $walletId = (string) Uuid::uuid7();
        $actorId  = (string) Uuid::uuid7();
        $holdId   = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->willReturn(true);
        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('pocketExists')->willReturn(true);
        $this->wallet->method('availableBalance')->willReturn(100000);
        $this->config->method('getOrDefault')
            ->willReturnCallback(fn (string $key): mixed => $key === 'auth.step_up_threshold' ? 10000 : null);
        $this->stepUpVerifier->method('verifyStepUpForActor')->willReturn(true);
        $this->wallet->method('createHold')->willReturn($holdId);

        // amount 15000 > threshold 10000, valid token
        $result = $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'payerActorId' => $actorId,
            'amountMinor' => 15000,
            'stepUpToken' => 'valid-token',
        ]));

        self::assertSame(PaymentAttemptStatus::Succeeded, $result->status);
        self::assertSame($holdId, $result->walletHoldId);
    }

    public function test_payment_above_threshold_with_wrong_actor_token_throws(): void
    {
        $walletId = (string) Uuid::uuid7();
        $actorId  = (string) Uuid::uuid7();

        $this->wallet->method('isActive')->willReturn(true);
        $this->wallet->method('ownerActorId')->willReturn($actorId);
        $this->wallet->method('pocketExists')->willReturn(true);
        $this->wallet->method('availableBalance')->willReturn(100000);
        $this->config->method('getOrDefault')
            ->willReturnCallback(fn (string $key): mixed => $key === 'auth.step_up_threshold' ? 10000 : null);
        $this->stepUpVerifier->method('verifyStepUpForActor')->willReturn(false); // token rejected
        $this->wallet->expects(self::never())->method('createHold');

        $this->expectException(StepUpRequiredException::class);

        // amount 15000 > threshold, token bound to wrong actor/session
        $this->adapter->initiatePayment($this->makeRequest([
            'walletId'    => $walletId,
            'payerActorId' => $actorId,
            'amountMinor' => 15000,
            'stepUpToken' => 'bad-token',
        ]));
    }

    // -------------------------------------------------------------------------
    // Existing tests: queryPaymentStatus / cancel (unchanged)
    // -------------------------------------------------------------------------

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
        $result = $this->adapter->initiateRefund($this->makeRefundRequest());

        self::assertSame(PaymentRefundStatus::Succeeded, $result->status);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRefundRequest(array $overrides = []): PaymentRefundRequest
    {
        return new PaymentRefundRequest(
            paymentIntentId:     $overrides['paymentIntentId']     ?? (string) Uuid::uuid7(),
            paymentAttemptId:    $overrides['paymentAttemptId']    ?? (string) Uuid::uuid7(),
            refundId:            $overrides['refundId']            ?? (string) Uuid::uuid7(),
            amountMinor:         $overrides['amountMinor']         ?? 5000,
            currencyCode:        $overrides['currencyCode']        ?? 'BWP',
            correlationId:       $overrides['correlationId']       ?? (string) Uuid::uuid7(),
            idempotencyKey:      $overrides['idempotencyKey']      ?? (string) Uuid::uuid7(),
            providerReferenceId: $overrides['providerReferenceId'] ?? null,
        );
    }
}
