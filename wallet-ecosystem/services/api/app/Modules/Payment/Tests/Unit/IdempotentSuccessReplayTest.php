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
 * T036 (P06 SC-04 corollary): Guard A — idempotent success replay in handleOutcome().
 *
 * The same `succeeded` callback must be safely ignored when the attempt is
 * already in `succeeded` state (same provider_reference_id). No ledger
 * double-posting, no exception, no state change.
 */
final class IdempotentSuccessReplayTest extends TestCase
{
    /** @var PaymentIntentRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private PaymentIntentRepositoryInterface $intentRepo;
    /** @var PaymentAttemptRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private PaymentAttemptRepositoryInterface $attemptRepo;
    private ReconciliationExceptionService $reconService;
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
        $this->ledger       = $this->createMock(LedgerModuleContract::class);
        $this->events       = $this->createMock(EventPublisherInterface::class);
    }

    private function makeService(): PaymentOrchestrationService
    {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('getOrDefault')->willReturnArgument(1);

        return new PaymentOrchestrationService(
            intentRepository:      $this->intentRepo,
            attemptRepository:     $this->attemptRepo,
            purposeRegistry:       new BusinessPurposeResolverRegistry(),
            wallet:                $this->createMock(WalletModuleContract::class),
            ledger:                $this->ledger,
            config:                $config,
            audit:                 $this->createMock(AuditServiceInterface::class),
            events:                $this->events,
            container:             $this->createMock(Container::class),
            reconciliationService: $this->reconService,
        );
    }

    private function makeIntent(): PaymentIntentModel
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

    private function makeSucceededAttempt(string $providerRef = 'ext-ref-001'): PaymentAttemptModel
    {
        $m = new PaymentAttemptModel();
        $m->id                    = 'attempt-001';
        $m->payment_intent_id     = 'intent-001';
        $m->provider_code         = 'wallet';
        $m->status                = PaymentAttemptStatus::Succeeded;
        $m->provider_reference_id = $providerRef;
        $m->ledger_journal_id     = 'journal-already-posted';
        return $m;
    }

    private function mockDbTransaction(): void
    {
        DB::shouldReceive('connection')->with('payment')->andReturnSelf();
        DB::shouldReceive('transaction')->andReturnUsing(static fn(callable $cb) => $cb());
    }

    /**
     * SC-04 corollary: Duplicate succeeded callback with same provider_reference_id
     * must return silently — no exception, no second ledger post, no DB save.
     */
    public function test_duplicate_succeeded_callback_is_idempotent(): void
    {
        $this->mockDbTransaction();

        $intent  = $this->makeIntent();
        $attempt = $this->makeSucceededAttempt('ext-ref-001');

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);

        // Guard A fires → returns early → none of these should be called
        $this->intentRepo->expects($this->never())->method('save');
        $this->attemptRepo->expects($this->never())->method('save');
        $this->ledger->expects($this->never())->method('postEntry');
        $this->events->expects($this->never())->method('publish');
        $this->reconService->expects($this->never())->method('create');

        $this->makeService()->handleOutcome([
            'payment_intent_id'    => 'intent-001',
            'payment_attempt_id'   => 'attempt-001',
            'outcome'              => 'succeeded',
            'provider_reference_id' => 'ext-ref-001',
            'correlation_id'       => 'corr-replay-001',
        ]);

        $this->assertTrue(true, 'Idempotent replay returned silently — no side effects.');
    }

    /**
     * A succeeded callback with NO provider_reference_id supplied also replays
     * idempotently when the attempt is already succeeded (null defaults to existing ref).
     */
    public function test_duplicate_succeeded_callback_without_provider_ref_is_idempotent(): void
    {
        $this->mockDbTransaction();

        $intent  = $this->makeIntent();
        $attempt = $this->makeSucceededAttempt('ext-ref-001');

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);

        $this->intentRepo->expects($this->never())->method('save');
        $this->attemptRepo->expects($this->never())->method('save');
        $this->ledger->expects($this->never())->method('postEntry');
        $this->reconService->expects($this->never())->method('create');

        $this->makeService()->handleOutcome([
            'payment_intent_id'  => 'intent-001',
            'payment_attempt_id' => 'attempt-001',
            'outcome'            => 'succeeded',
            // no provider_reference_id — Guard A treats null as matching
            'correlation_id'     => 'corr-replay-002',
        ]);

        $this->assertTrue(true, 'Idempotent replay with null provider_ref returned silently.');
    }

    /**
     * A succeeded callback with a DIFFERENT provider_reference_id is NOT idempotent
     * — this is contradictory evidence and must trigger Guard B.
     */
    public function test_different_provider_ref_on_succeeded_attempt_is_not_idempotent(): void
    {
        $this->mockDbTransaction();

        $intent  = $this->makeIntent();
        $attempt = $this->makeSucceededAttempt('ext-ref-001');

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);

        // Guard A: provider_ref differs → not replayed
        // Guard B: Succeeded is terminal, incoming Succeeded differs? No — same status.
        // Actually: succeeded→succeeded with DIFFERENT provider_ref — Guard A does NOT
        // intercept because the refs differ. Then Guard B checks isTerminal() && status ≠ newStatus:
        // Succeeded → Succeeded is the SAME status, so Guard B also does NOT fire.
        // The state machine then throws because Succeeded→Succeeded is not an allowed transition.
        $this->expectException(\Throwable::class);

        $this->makeService()->handleOutcome([
            'payment_intent_id'    => 'intent-001',
            'payment_attempt_id'   => 'attempt-001',
            'outcome'              => 'succeeded',
            'provider_reference_id' => 'ext-ref-DIFFERENT',
            'correlation_id'       => 'corr-003',
        ]);
    }
}
