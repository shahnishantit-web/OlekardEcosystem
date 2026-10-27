<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Jobs\PaymentAttemptCrashRecoveryJob;
use App\Modules\Payment\Application\Jobs\PaymentAttemptSingleRecoveryJob;
use App\Modules\Payment\Domain\Contracts\PaymentOutcomeProcessor;
use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class CrashRecoveryJobTest extends TestCase
{
    private PaymentAttemptRepositoryInterface&MockObject $attemptRepo;
    private PaymentIntentRepositoryInterface&MockObject  $intentRepo;
    private PaymentOutcomeProcessor&MockObject       $orchestration;
    private WalletModuleContract&MockObject              $wallet;
    private ConfigurationServiceInterface&MockObject     $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attemptRepo   = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->intentRepo    = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->orchestration = $this->createMock(PaymentOutcomeProcessor::class);
        $this->wallet        = $this->createMock(WalletModuleContract::class);
        $this->config        = $this->createMock(ConfigurationServiceInterface::class);

        $this->config->method('getOrDefault')->willReturnCallback(
            static fn (string $key, mixed $default) => $default
        );
    }

    /** Test 1: Scan job dispatches one recovery job per stuck attempt */
    public function test_scan_job_dispatches_per_stuck_attempt(): void
    {
        Queue::fake();

        $attempt1       = new PaymentAttemptModel();
        $attempt1->id   = 'attempt-uuid-1';

        $attempt2       = new PaymentAttemptModel();
        $attempt2->id   = 'attempt-uuid-2';

        $this->attemptRepo
            ->method('findStuckInSubmitting')
            ->with(60)
            ->willReturn(new Collection([$attempt1, $attempt2]));

        $job = new PaymentAttemptCrashRecoveryJob();
        $job->handle($this->attemptRepo, $this->config);

        Queue::assertPushed(PaymentAttemptSingleRecoveryJob::class, 2);
        Queue::assertPushed(
            PaymentAttemptSingleRecoveryJob::class,
            fn ($j) => $j->attemptId === 'attempt-uuid-1',
        );
        Queue::assertPushed(
            PaymentAttemptSingleRecoveryJob::class,
            fn ($j) => $j->attemptId === 'attempt-uuid-2',
        );
    }

    /** Test 2: Succeeded provider status → handleOutcome(succeeded) */
    public function test_single_recovery_dispatches_succeeded_when_provider_succeeded(): void
    {
        $attempt                        = new PaymentAttemptModel();
        $attempt->id                    = 'attempt-uuid-1';
        $attempt->status                = PaymentAttemptStatus::Submitting;
        $attempt->payment_intent_id     = 'intent-uuid-1';
        $attempt->provider_code         = 'test_provider';
        $attempt->provider_reference_id = null;
        $attempt->operation_id          = 'op-123';
        $attempt->wallet_hold_id        = null;

        $intent                 = new PaymentIntentModel();
        $intent->id             = 'intent-uuid-1';
        $intent->correlation_id = 'corr-abc';

        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('findById')->willReturn($intent);

        $adapter = $this->createMock(PaymentAdapterInterface::class);
        $adapter->method('queryPaymentStatus')->willReturn(
            new PaymentStatusResult(
                status: PaymentAttemptStatus::Succeeded,
                providerReferenceId: 'prov-ref-99',
            )
        );

        $this->app->bind('payment.adapter.test_provider', static fn () => $adapter);

        $this->orchestration->expects($this->once())->method('handleOutcome')->with(
            $this->callback(fn ($data) => $data['outcome'] === 'succeeded' && $data['provider_reference_id'] === 'prov-ref-99')
        );

        $job = new PaymentAttemptSingleRecoveryJob('attempt-uuid-1');
        $job->handle($this->attemptRepo, $this->intentRepo, $this->orchestration, $this->wallet);
    }

    /** Missing reference is not evidence of non-execution. */
    public function test_single_recovery_preserves_unknown_when_provider_has_no_record(): void
    {
        $attempt                        = new PaymentAttemptModel();
        $attempt->id                    = 'attempt-uuid-1';
        $attempt->status                = PaymentAttemptStatus::Submitting;
        $attempt->payment_intent_id     = 'intent-uuid-1';
        $attempt->provider_code         = 'test_provider';
        $attempt->provider_reference_id = null;
        $attempt->operation_id          = 'op-123';
        $attempt->wallet_hold_id        = null;

        $intent                 = new PaymentIntentModel();
        $intent->id             = 'intent-uuid-1';
        $intent->correlation_id = 'corr-abc';

        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('findById')->willReturn($intent);

        $adapter = $this->createMock(PaymentAdapterInterface::class);
        $adapter->method('queryPaymentStatus')->willReturn(
            new PaymentStatusResult(status: PaymentAttemptStatus::Unknown, providerReferenceId: null)
        );
        $this->app->bind('payment.adapter.test_provider', static fn () => $adapter);

        $this->orchestration->expects($this->never())->method('handleOutcome');
        $this->wallet->expects($this->never())->method('releaseHold');
        $this->expectUnknownRecord();

        $job = new PaymentAttemptSingleRecoveryJob('attempt-uuid-1');
        $job->handle($this->attemptRepo, $this->intentRepo, $this->orchestration, $this->wallet);
        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);
    }

    /** Test 4: No adapter registered → UNKNOWN, original hold retained. */
    public function test_single_recovery_transitions_to_unknown_when_no_adapter(): void
    {
        $attempt                        = new PaymentAttemptModel();
        $attempt->id                    = 'attempt-uuid-1';
        $attempt->status                = PaymentAttemptStatus::Submitting;
        $attempt->payment_intent_id     = 'intent-uuid-1';
        $attempt->provider_code         = 'unregistered_provider';
        $attempt->provider_reference_id = null;
        $attempt->operation_id          = 'op-456';
        $attempt->wallet_hold_id        = 'hold-xyz';

        $intent                 = new PaymentIntentModel();
        $intent->id             = 'intent-uuid-1';
        $intent->correlation_id = 'corr-def';

        $locked         = new PaymentAttemptModel();
        $locked->id     = $attempt->id;
        $locked->status = PaymentAttemptStatus::Submitting;

        $this->attemptRepo
            ->method('findById')
            ->willReturnOnConsecutiveCalls($attempt, $locked);
        $this->intentRepo->method('findById')->willReturn($intent);

        $this->orchestration->expects($this->never())->method('handleOutcome');
        $this->wallet->expects($this->never())->method('releaseHold');
        $this->expectUnknownRecord();

        $job = new PaymentAttemptSingleRecoveryJob('attempt-uuid-1');
        $job->handle($this->attemptRepo, $this->intentRepo, $this->orchestration, $this->wallet);
        $this->assertSame(PaymentAttemptStatus::Unknown, $locked->status);
        $this->assertSame('hold-xyz', $attempt->wallet_hold_id);
    }

    /** Test 5: Attempt already in terminal state → skip processing silently */
    public function test_single_recovery_skips_terminal_attempt(): void
    {
        $attempt                    = new PaymentAttemptModel();
        $attempt->id                = 'attempt-uuid-1';
        $attempt->status            = PaymentAttemptStatus::Succeeded;
        $attempt->payment_intent_id = 'intent-uuid-1';
        $attempt->provider_code     = 'test_provider';

        $this->attemptRepo->method('findById')->willReturn($attempt);

        $this->orchestration->expects($this->never())->method('handleOutcome');
        $this->wallet->expects($this->never())->method('releaseHold');

        $job = new PaymentAttemptSingleRecoveryJob('attempt-uuid-1');
        $job->handle($this->attemptRepo, $this->intentRepo, $this->orchestration, $this->wallet);
    }

    /** Test 6: Provider Failed → handleOutcome(failed, crash_recovery_provider_confirmed_failed) */
    public function test_single_recovery_marks_failed_when_provider_confirmed_failed(): void
    {
        $attempt                        = new PaymentAttemptModel();
        $attempt->id                    = 'attempt-uuid-1';
        $attempt->status                = PaymentAttemptStatus::Submitting;
        $attempt->payment_intent_id     = 'intent-uuid-1';
        $attempt->provider_code         = 'test_provider';
        $attempt->provider_reference_id = 'prov-ref-99';
        $attempt->operation_id          = 'op-789';
        $attempt->wallet_hold_id        = null;

        $intent                 = new PaymentIntentModel();
        $intent->id             = 'intent-uuid-1';
        $intent->correlation_id = 'corr-ghi';

        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('findById')->willReturn($intent);

        $adapter = $this->createMock(PaymentAdapterInterface::class);
        $adapter->method('queryPaymentStatus')->willReturn(
            new PaymentStatusResult(
                status: PaymentAttemptStatus::Failed,
                failureReason: 'provider_declined',
            )
        );
        $this->app->bind('payment.adapter.test_provider', static fn () => $adapter);

        $this->orchestration->expects($this->once())->method('handleOutcome')->with(
            $this->callback(fn ($data) => $data['outcome'] === 'failed'
                && $data['failure_reason'] === 'provider_declined')
        );

        $job = new PaymentAttemptSingleRecoveryJob('attempt-uuid-1');
        $job->handle($this->attemptRepo, $this->intentRepo, $this->orchestration, $this->wallet);
    }

    private function expectUnknownRecord(): void
    {
        $connection = $this->createMock(\Illuminate\Database\Connection::class);
        $connection->method('transaction')->willReturnCallback(static fn ($callback) => $callback());
        $builder = $this->createMock(\Illuminate\Database\Query\Builder::class);
        $builder->expects($this->once())->method('insertOrIgnore')->with($this->callback(
            static fn ($data) => $data['exception_type'] === 'unknown_outcome' && $data['status'] === 'open'
        ))->willReturn(1);
        $connection->method('table')->with('payment.reconciliation_exceptions')->willReturn($builder);
        \Illuminate\Support\Facades\DB::shouldReceive('connection')->with('payment')->andReturn($connection);
    }
}
