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
use App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T035 (P06 SC-08): Guard C — EXCESS_CAPTURE in handleOutcome().
 *
 * EXCESS_CAPTURE is non-blocking: the succeeded transition completes but a
 * ReconciliationException is created as a side-effect for operator review.
 */
final class ExcessCaptureTest extends TestCase
{
    /** @var PaymentIntentRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private PaymentIntentRepositoryInterface $intentRepo;
    /** @var PaymentAttemptRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private PaymentAttemptRepositoryInterface $attemptRepo;
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

    private function makeService(int $toleranceMinor = 0): PaymentOrchestrationService
    {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('getOrDefault')
            ->willReturnCallback(static fn(string $key, mixed $default) => match ($key) {
                'payment.excess_capture_tolerance_minor' => $toleranceMinor,
                default                                  => $default,
            });

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

    private function makeIntent(int $amountMinor = 10000): PaymentIntentModel
    {
        $m = new PaymentIntentModel();
        $m->id                = 'intent-001';
        $m->status            = PaymentIntentStatus::Processing;
        $m->accounting_status = PaymentAccountingStatus::PendingLedger;
        $m->fulfilment_status = PaymentFulfilmentStatus::Pending;
        $m->amount_minor      = $amountMinor;
        $m->currency_code     = 'BWP';
        $m->purpose_type      = 'bill';
        $m->purpose_reference = 'ref-001';
        $m->organisation_id   = 'org-001';
        $m->payer_actor_id    = 'actor-001';
        return $m;
    }

    private function makeAttempt(PaymentAttemptStatus $status = PaymentAttemptStatus::Processing): PaymentAttemptModel
    {
        $m = new PaymentAttemptModel();
        $m->id                = 'attempt-001';
        $m->payment_intent_id = 'intent-001';
        $m->provider_code     = 'wallet';
        $m->status            = $status;
        return $m;
    }

    private function mockDbTransaction(): void
    {
        DB::shouldReceive('connection')->with('payment')->andReturnSelf();
        DB::shouldReceive('transaction')->andReturnUsing(static fn(callable $cb) => $cb());
    }

    /**
     * SC-08: captured_amount_minor exceeds intent amount + 0 tolerance
     * → succeeded transition completes AND ExcessCapture exception created.
     */
    public function test_excess_capture_creates_exception_without_blocking_succeeded(): void
    {
        $this->mockDbTransaction();

        $intent  = $this->makeIntent(amountMinor: 10000);
        $attempt = $this->makeAttempt();
        $savedIntents = [];

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('save')->willReturnCallback(static function (PaymentIntentModel $i) use (&$savedIntents): void {
            $savedIntents[] = clone $i;
        });
        $this->ledger->method('postEntry')->willReturn('journal-001');

        $exceptionData = null;
        $this->reconService
            ->expects($this->once())
            ->method('create')
            ->with(
                ReconciliationExceptionType::ExcessCapture,
                $this->callback(static function (array $d) use (&$exceptionData): bool {
                    $exceptionData = $d;
                    return true;
                }),
            )
            ->willReturn(new ReconciliationExceptionModel());

        // Must NOT throw — excess capture is non-blocking
        $this->makeService(toleranceMinor: 0)->handleOutcome([
            'payment_intent_id'    => 'intent-001',
            'payment_attempt_id'   => 'attempt-001',
            'outcome'              => 'succeeded',
            'captured_amount_minor' => 10001,
            'provider_reference_id' => 'ext-ref-001',
            'correlation_id'       => 'corr-001',
        ]);

        // Attempt should be succeeded
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        // Exception data must carry the right amounts
        $this->assertNotNull($exceptionData);
        $this->assertSame(10001, $exceptionData['detected_amount_minor']);
        $this->assertSame(10000, $exceptionData['expected_amount_minor']);
        $this->assertSame('BWP',  $exceptionData['currency_code']);
    }

    /**
     * When captured amount is within tolerance, NO exception must be created.
     */
    public function test_within_tolerance_does_not_create_exception(): void
    {
        $this->mockDbTransaction();

        $intent  = $this->makeIntent(amountMinor: 10000);
        $attempt = $this->makeAttempt();

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->ledger->method('postEntry')->willReturn('journal-001');

        // tolerance = 50, captured = 10040 → 10040 <= 10000 + 50 → no exception
        $this->reconService->expects($this->never())->method('create');

        $this->makeService(toleranceMinor: 50)->handleOutcome([
            'payment_intent_id'    => 'intent-001',
            'payment_attempt_id'   => 'attempt-001',
            'outcome'              => 'succeeded',
            'captured_amount_minor' => 10040,
            'correlation_id'       => 'corr-001',
        ]);

        $this->assertTrue(true, 'No exception created within tolerance.');
    }

    /**
     * When captured_amount_minor is not supplied, intent amount is assumed — never triggers excess.
     */
    public function test_no_captured_amount_does_not_trigger_excess_capture(): void
    {
        $this->mockDbTransaction();

        $intent  = $this->makeIntent(amountMinor: 10000);
        $attempt = $this->makeAttempt();

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->ledger->method('postEntry')->willReturn('journal-001');

        $this->reconService->expects($this->never())->method('create');

        $this->makeService()->handleOutcome([
            'payment_intent_id'  => 'intent-001',
            'payment_attempt_id' => 'attempt-001',
            'outcome'            => 'succeeded',
            'correlation_id'     => 'corr-001',
            // no captured_amount_minor — defaults to intent amount
        ]);

        $this->assertTrue(true, 'No exception created when captured amount is absent.');
    }

    /**
     * Excess capture guard must only fire on succeeded outcome, not on failed.
     */
    public function test_excess_capture_guard_does_not_fire_on_failed_outcome(): void
    {
        $this->mockDbTransaction();

        $intent  = $this->makeIntent(amountMinor: 10000);
        $attempt = $this->makeAttempt();

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);

        $this->reconService->expects($this->never())->method('create');

        $this->makeService()->handleOutcome([
            'payment_intent_id'    => 'intent-001',
            'payment_attempt_id'   => 'attempt-001',
            'outcome'              => 'failed',
            'captured_amount_minor' => 99999, // large amount — irrelevant for failed
            'correlation_id'       => 'corr-001',
        ]);

        $this->assertTrue(true, 'Excess capture guard skipped for failed outcome.');
    }
}
