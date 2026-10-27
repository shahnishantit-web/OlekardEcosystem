<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentFulfilmentStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T022 (P07 US3): Guard D — DUPLICATE_CAPTURE in handleOutcome().
 *
 * Guard D is inserted between Guard A and Guard B. When an attempt is already
 * Succeeded and a second succeeded callback arrives with a *different* non-null
 * provider reference ID, it means the provider charged twice. A
 * DuplicateCapture reconciliation exception is recorded and the handler returns
 * silently — no state mutation, no duplicate ledger entry.
 */
final class DuplicateCaptureTest extends TestCase
{
    /** @var PaymentIntentRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private PaymentIntentRepositoryInterface $intentRepo;
    /** @var PaymentAttemptRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private PaymentAttemptRepositoryInterface $attemptRepo;
    /** @var ReconciliationExceptionService&\PHPUnit\Framework\MockObject\MockObject */
    private ReconciliationExceptionService $reconService;
    /** @var AuditServiceInterface&\PHPUnit\Framework\MockObject\MockObject */
    private AuditServiceInterface $audit;
    /** @var LedgerModuleContract&\PHPUnit\Framework\MockObject\MockObject */
    private LedgerModuleContract $ledger;
    /** @var EventPublisherInterface&\PHPUnit\Framework\MockObject\MockObject */
    private EventPublisherInterface $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->intentRepo   = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->attemptRepo  = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->reconService = $this->createMock(ReconciliationExceptionService::class);
        $this->audit        = $this->createMock(AuditServiceInterface::class);
        $this->ledger       = $this->createMock(LedgerModuleContract::class);
        $this->events       = $this->createMock(EventPublisherInterface::class);
    }

    private function makeService(): PaymentOrchestrationService
    {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('getOrDefault')
            ->willReturnCallback(static fn (string $key, mixed $default) => $default);

        return new PaymentOrchestrationService(
            intentRepository:      $this->intentRepo,
            attemptRepository:     $this->attemptRepo,
            purposeRegistry:       new BusinessPurposeResolverRegistry(),
            wallet:                $this->createMock(WalletModuleContract::class),
            ledger:                $this->ledger,
            config:                $config,
            audit:                 $this->audit,
            events:                $this->events,
            container:             $this->createMock(Container::class),
            reconciliationService: $this->reconService,
        );
    }

    private function makeSucceededIntent(): PaymentIntentModel
    {
        $m = new PaymentIntentModel();
        $m->id                = 'intent-001';
        $m->status            = PaymentIntentStatus::Succeeded;
        $m->accounting_status = PaymentAccountingStatus::LedgerPosted;
        $m->fulfilment_status = PaymentFulfilmentStatus::Pending;
        $m->amount_minor      = 10000;
        $m->currency_code     = 'BWP';
        $m->purpose_type      = 'bill';
        $m->purpose_reference = 'ref-001';
        $m->organisation_id   = 'org-001';
        $m->payer_actor_id    = 'actor-001';
        return $m;
    }

    private function makeSucceededAttempt(string $existingRef = 'prov-ref-original'): PaymentAttemptModel
    {
        $m = new PaymentAttemptModel();
        $m->id                    = 'attempt-001';
        $m->payment_intent_id     = 'intent-001';
        $m->provider_code         = 'wallet';
        $m->status                = PaymentAttemptStatus::Succeeded;
        $m->provider_reference_id = $existingRef;
        return $m;
    }

    private function mockDbTransaction(): void
    {
        DB::shouldReceive('connection')->with('payment')->andReturnSelf();
        DB::shouldReceive('transaction')->andReturnUsing(static fn (callable $cb) => $cb());
    }

    /**
     * SC-D1: Attempt already Succeeded. Incoming succeeded callback has a
     * DIFFERENT provider_reference_id → Guard D fires, creates DuplicateCapture
     * exception, returns without further state mutation.
     */
    public function test_duplicate_capture_creates_exception_and_returns_silently(): void
    {
        $this->mockDbTransaction();

        $intent  = $this->makeSucceededIntent();
        $attempt = $this->makeSucceededAttempt('prov-ref-original');

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);

        $this->reconService->expects($this->once())->method('create')->with(
            ReconciliationExceptionType::DuplicateCapture,
            $this->callback(fn ($data) => $data['provider_reference_id'] === 'prov-ref-duplicate'
                && $data['payment_attempt_id'] === 'attempt-001')
        )->willReturn(new \App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel());

        // No save calls — Guard D returns before any mutation
        $this->attemptRepo->expects($this->never())->method('save');
        $this->intentRepo->expects($this->never())->method('save');

        $service = $this->makeService();
        $service->handleOutcome([
            'payment_intent_id'     => 'intent-001',
            'payment_attempt_id'    => 'attempt-001',
            'outcome'               => 'succeeded',
            'provider_reference_id' => 'prov-ref-duplicate',
            'correlation_id'        => 'corr-001',
        ]);
    }

    /**
     * SC-D2: Attempt already Succeeded. Incoming succeeded callback has the
     * SAME provider_reference_id → Guard A fires (idempotent replay), not Guard D.
     * No exception created.
     */
    public function test_same_reference_is_idempotent_replay_not_duplicate_capture(): void
    {
        $this->mockDbTransaction();

        $intent  = $this->makeSucceededIntent();
        $attempt = $this->makeSucceededAttempt('prov-ref-original');

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);

        // Guard A takes this path — no reconciliation exception
        $this->reconService->expects($this->never())->method('create');
        $this->attemptRepo->expects($this->never())->method('save');

        $service = $this->makeService();
        $service->handleOutcome([
            'payment_intent_id'     => 'intent-001',
            'payment_attempt_id'    => 'attempt-001',
            'outcome'               => 'succeeded',
            'provider_reference_id' => 'prov-ref-original',
            'correlation_id'        => 'corr-001',
        ]);
    }

    /**
     * SC-D3: Attempt already Succeeded. Incoming succeeded callback has null
     * provider_reference_id → Guard A fires (null treated as same), not Guard D.
     */
    public function test_null_incoming_reference_is_idempotent_replay(): void
    {
        $this->mockDbTransaction();

        $intent  = $this->makeSucceededIntent();
        $attempt = $this->makeSucceededAttempt('prov-ref-original');

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);

        $this->reconService->expects($this->never())->method('create');

        $service = $this->makeService();
        $service->handleOutcome([
            'payment_intent_id'  => 'intent-001',
            'payment_attempt_id' => 'attempt-001',
            'outcome'            => 'succeeded',
            'correlation_id'     => 'corr-001',
        ]);
    }
}
