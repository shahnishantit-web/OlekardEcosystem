<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Exceptions\DuplicateAttemptException;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * T018 (P06 US2): Concurrent attempt / duplicate idempotency-key guard.
 *
 * Verifies that two callers with the same idempotency key produce exactly one
 * attempt record (idempotent replay) and that an in-flight attempt blocks a
 * second one with a different key (DuplicateAttemptException).
 *
 * All paths exit before DB::transaction, so no database or app bootstrap needed.
 */
final class ConcurrentAttemptTest extends TestCase
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

    private function makeExistingAttempt(string $id = 'attempt-001'): PaymentAttemptModel
    {
        $attempt = new PaymentAttemptModel();
        $attempt->id              = $id;
        $attempt->payment_intent_id = 'intent-001';
        $attempt->provider_code   = 'wallet';
        $attempt->status          = PaymentAttemptStatus::Processing;
        $attempt->idempotency_key = 'idem-001';
        $attempt->operation_id    = (string) \Ramsey\Uuid\Uuid::uuid7();
        return $attempt;
    }

    private function makeInFlightAttempt(): PaymentAttemptModel
    {
        $attempt = new PaymentAttemptModel();
        $attempt->id              = 'attempt-inflight';
        $attempt->payment_intent_id = 'intent-001';
        $attempt->status          = PaymentAttemptStatus::Processing;
        $attempt->idempotency_key = 'idem-other';
        return $attempt;
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * SC-02: Two callers with the same idempotency key receive the same attempt ID.
     * The second call is a replay — no new attempt is created, no repository save occurs.
     */
    public function test_duplicate_idempotency_key_returns_same_attempt(): void
    {
        $existing = $this->makeExistingAttempt('attempt-001');

        // Both calls find the existing attempt by key
        $this->attemptRepo->method('findByIdempotencyKey')
            ->with('idem-001')
            ->willReturn($existing);

        // save() must never be called — replay returns early
        $this->attemptRepo->expects($this->never())->method('save');

        $result1 = $this->service->initiateAttempt([
            'payment_intent_id' => 'intent-001',
            'provider_code'     => 'wallet',
            'payer_actor_id'    => 'actor-001',
            'idempotency_key'   => 'idem-001',
            'correlation_id'    => 'corr-001',
        ]);

        $this->assertSame('attempt-001', $result1['attempt_id']);

        // Second call with same key — also replays
        $result2 = $this->service->initiateAttempt([
            'payment_intent_id' => 'intent-001',
            'provider_code'     => 'wallet',
            'payer_actor_id'    => 'actor-001',
            'idempotency_key'   => 'idem-001',
            'correlation_id'    => 'corr-002',
        ]);

        $this->assertSame('attempt-001', $result2['attempt_id']);
    }

    /**
     * SC-02: A second caller with a DIFFERENT idempotency key cannot start a new
     * attempt when an in-flight (processing) attempt already exists.
     * DuplicateAttemptException must be thrown.
     */
    public function test_different_key_blocked_when_in_flight_attempt_exists(): void
    {
        $intent = new PaymentIntentModel();
        $intent->id             = 'intent-001';
        $intent->status         = PaymentIntentStatus::Processing;
        $intent->payer_actor_id = 'actor-001';
        $intent->amount_minor   = 10000;
        $intent->currency_code  = 'BWP';
        $intent->correlation_id = 'corr-001';

        // Different key — not a replay
        $this->attemptRepo->method('findByIdempotencyKey')->willReturn(null);

        // Intent found
        $this->intentRepo->method('findById')->willReturn($intent);

        // An in-flight attempt exists
        $this->attemptRepo->method('findActiveForIntent')->willReturn($this->makeInFlightAttempt());

        $this->expectException(DuplicateAttemptException::class);

        $this->service->initiateAttempt([
            'payment_intent_id' => 'intent-001',
            'provider_code'     => 'wallet',
            'payer_actor_id'    => 'actor-001',
            'idempotency_key'   => 'idem-new',
            'correlation_id'    => 'corr-003',
        ]);
    }
}
