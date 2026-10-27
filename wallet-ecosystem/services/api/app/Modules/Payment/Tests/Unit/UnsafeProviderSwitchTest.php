<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Exceptions\UnsafeProviderSwitchException;
use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T019 (P06 US2): UNSAFE_PROVIDER_SWITCH guard.
 *
 * Verifies that when a prior attempt for provider A is in UNKNOWN state,
 * attempting to start a new attempt via a different provider B is blocked.
 * An unresolved same-provider operation also blocks a new key; original-key replay remains available.
 *
 * The UNSAFE_PROVIDER_SWITCH guard fires BEFORE DB::transaction, so no
 * database or app bootstrap is needed for these tests.
 */
final class UnsafeProviderSwitchTest extends TestCase
{
    /** @var MockObject&PaymentIntentRepositoryInterface */
    private MockObject $intentRepo;
    /** @var MockObject&PaymentAttemptRepositoryInterface */
    private MockObject $attemptRepo;
    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;

    private PaymentOrchestrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->intentRepo  = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->attemptRepo = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->config      = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('getOrDefault')->willReturn('15');

        $this->service = new PaymentOrchestrationService(
            intentRepository:  $this->intentRepo,
            attemptRepository: $this->attemptRepo,
            purposeRegistry:   new BusinessPurposeResolverRegistry(),
            wallet:            $this->createMock(WalletModuleContract::class),
            ledger:            $this->createMock(LedgerModuleContract::class),
            config:            $this->config,
            audit:             $this->createMock(AuditServiceInterface::class),
            events:            $this->createMock(EventPublisherInterface::class),
            container:         $this->createMock(Container::class),
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeIntent(string $status = 'pending'): PaymentIntentModel
    {
        $intent = new PaymentIntentModel();
        $intent->id              = 'intent-001';
        $intent->payer_actor_id  = 'actor-001';
        $intent->currency_code   = 'BWP';
        $intent->amount_minor    = 10000;
        $intent->status          = PaymentIntentStatus::from($status);
        $intent->correlation_id  = 'corr-001';
        $intent->expires_at      = now()->addMinutes(30);
        return $intent;
    }

    private function makeUnknownAttempt(string $providerCode): PaymentAttemptModel
    {
        $attempt = new PaymentAttemptModel();
        $attempt->id               = 'attempt-unknown-001';
        $attempt->payment_intent_id = 'intent-001';
        $attempt->provider_code    = $providerCode;
        $attempt->status           = PaymentAttemptStatus::Unknown;
        $attempt->idempotency_key  = 'idem-prior';
        $attempt->operation_id     = (string) \Ramsey\Uuid\Uuid::uuid7();
        return $attempt;
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * SC-05: Attempting to start a new attempt via provider B when a prior attempt
     * via provider A is in UNKNOWN state must throw UnsafeProviderSwitchException.
     * This prevents double-charging across different providers.
     */
    public function test_different_provider_blocked_when_unknown_attempt_exists(): void
    {
        $this->attemptRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->intentRepo->method('findById')->willReturn($this->makeIntent());
        $this->attemptRepo->method('findActiveForIntent')->willReturn(null);

        // UNKNOWN attempt exists for provider-a
        $this->attemptRepo->method('findUnknownForIntent')
            ->with('intent-001')
            ->willReturn($this->makeUnknownAttempt('provider-a'));

        $this->expectException(UnsafeProviderSwitchException::class);

        $this->service->initiateAttempt([
            'payment_intent_id' => 'intent-001',
            'provider_code'     => 'provider-b', // different provider
            'payer_actor_id'    => 'actor-001',
            'idempotency_key'   => 'idem-new',
            'correlation_id'    => 'corr-001',
        ]);
    }

    /** An unresolved same-provider operation blocks a new idempotency key. */
    public function test_new_operation_same_provider_is_blocked_when_unknown_attempt_exists(): void
    {
        $this->attemptRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->intentRepo->method('findById')->willReturn($this->makeIntent('pending'));
        $this->attemptRepo->method('findActiveForIntent')->willReturn(null);

        // UNKNOWN attempt exists for wallet (same as the new request)
        $this->attemptRepo->method('findUnknownForIntent')
            ->with('intent-001')
            ->willReturn($this->makeUnknownAttempt('wallet'));

        // No adapter or network call is permitted for a new operation.
        $this->expectException(\App\Modules\Payment\Domain\Exceptions\DuplicateAttemptException::class);

        $this->service->initiateAttempt([
            'payment_intent_id' => 'intent-001',
            'provider_code'     => 'wallet', // same provider as UNKNOWN attempt
            'payer_actor_id'    => 'actor-001',
            'idempotency_key'   => 'idem-new',
            'correlation_id'    => 'corr-001',
        ]);
    }

    /**
     * Verify the exception carries the correct context for operator remediation.
     */
    public function test_exception_carries_intent_and_provider_context(): void
    {
        $this->attemptRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->intentRepo->method('findById')->willReturn($this->makeIntent());
        $this->attemptRepo->method('findActiveForIntent')->willReturn(null);
        $this->attemptRepo->method('findUnknownForIntent')
            ->willReturn($this->makeUnknownAttempt('provider-a'));

        try {
            $this->service->initiateAttempt([
                'payment_intent_id' => 'intent-001',
                'provider_code'     => 'provider-b',
                'payer_actor_id'    => 'actor-001',
                'idempotency_key'   => 'idem-new',
                'correlation_id'    => 'corr-001',
            ]);
            $this->fail('Expected UnsafeProviderSwitchException');
        } catch (UnsafeProviderSwitchException $e) {
            $this->assertSame('intent-001', $e->intentId);
            $this->assertSame('provider-a', $e->unknownProvider);
            $this->assertSame('provider-b', $e->requestedProvider);
        }
    }

    public function test_original_key_replays_unknown_attempt_without_new_operation(): void
    {
        $attempt = $this->makeUnknownAttempt('wallet');
        $this->attemptRepo->method('findByIdempotencyKey')->willReturn($attempt);
        $this->intentRepo->expects($this->never())->method('findById');
        $result = $this->service->initiateAttempt([
            'payment_intent_id' => 'intent-001', 'provider_code' => 'wallet',
            'payer_actor_id' => 'actor-001', 'idempotency_key' => 'original-key', 'correlation_id' => 'corr-001',
        ]);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame($attempt->id, $result['attempt_id']);
    }
}
