<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Jobs\PaymentAttemptTimeoutJob;
use App\Modules\Payment\Domain\Contracts\PaymentOutcomeProcessor;
use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T023 (P06 US3): UNKNOWN state on timeout.
 *
 * Verifies that when the adapter returns an ambiguous status (not definitively
 * succeeded or failed), the timeout job transitions the attempt to UNKNOWN
 * and does NOT move the intent to a terminal state.
 *
 * Uses DB::shouldReceive so transaction callbacks execute inline.
 */
final class TimeoutToUnknownTest extends TestCase
{
    /** @var MockObject&PaymentAttemptRepositoryInterface */
    private MockObject $attemptRepo;
    /** @var MockObject&PaymentIntentRepositoryInterface */
    private MockObject $intentRepo;
    /** @var MockObject&PaymentAdapterInterface */
    private MockObject $adapter;
    /** @var MockObject&PaymentOutcomeProcessor */
    private MockObject $orchestration;

    /** Last attempt passed to $attemptRepo->save() */
    private ?PaymentAttemptModel $savedAttempt = null;

    protected function setUp(): void
    {
        parent::setUp();

        DB::shouldReceive('connection')->with('platform')->andReturnSelf();
        DB::shouldReceive('connection')->with('payment')->andReturnSelf();
        DB::shouldReceive('transaction')->andReturnUsing(fn(callable $cb) => $cb());
        DB::shouldReceive('table')->andReturnSelf();
        DB::shouldReceive('insertOrIgnore')->andReturn(true);

        $this->attemptRepo   = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->intentRepo    = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->adapter       = $this->createMock(PaymentAdapterInterface::class);
        $this->orchestration = $this->createMock(PaymentOutcomeProcessor::class);

        $this->savedAttempt = null;
        $this->attemptRepo->method('save')
            ->willReturnCallback(function (PaymentAttemptModel $model): void {
                $this->savedAttempt = $model;
            });
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeAttempt(
        string $status = 'processing',
        ?string $providerRef = 'prov-ref-001',
        ?string $walletHoldId = null,
    ): PaymentAttemptModel {
        $attempt = new PaymentAttemptModel();
        $attempt->id                  = 'attempt-001';
        $attempt->payment_intent_id   = 'intent-001';
        $attempt->provider_code       = 'wallet';
        $attempt->status              = PaymentAttemptStatus::from($status);
        $attempt->provider_reference_id = $providerRef;
        $attempt->wallet_hold_id      = $walletHoldId;
        $attempt->idempotency_key     = 'idem-001';
        return $attempt;
    }

    private function makeIntent(string $status = 'processing'): PaymentIntentModel
    {
        $intent = new PaymentIntentModel();
        $intent->id             = 'intent-001';
        $intent->status         = PaymentIntentStatus::from($status);
        $intent->payer_actor_id = 'actor-001';
        $intent->correlation_id = 'corr-001';
        return $intent;
    }

    private function makeJob(): PaymentAttemptTimeoutJob
    {
        $job = new PaymentAttemptTimeoutJob('attempt-001');

        // Bind adapter in app container for the job to resolve
        app()->bind('payment.adapter.wallet', fn() => $this->adapter);

        return $job;
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * SC-03: When the adapter returns an ambiguous status (AwaitingAction, Processing,
     * Submitting, etc.) on a timeout check, the attempt transitions to UNKNOWN.
     * The intent must NOT move to a terminal state (it remains non-terminal for
     * subsequent reconciliation).
     */
    public function test_ambiguous_status_transitions_to_unknown(): void
    {
        $attempt = $this->makeAttempt('processing', 'prov-ref-001');
        $intent  = $this->makeIntent('processing');

        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('findById')->willReturn($intent);

        // Adapter returns an ambiguous status — not Succeeded, not Failed
        $this->adapter->method('queryPaymentStatus')
            ->willReturn(new PaymentStatusResult(status: PaymentAttemptStatus::AwaitingAction));

        $this->makeJob()->handle($this->attemptRepo, $this->intentRepo, $this->orchestration);

        $this->assertNotNull($this->savedAttempt, 'Attempt must be updated');
        $this->assertSame(
            PaymentAttemptStatus::Unknown,
            $this->savedAttempt->status,
            'Ambiguous timeout must transition attempt to UNKNOWN'
        );

        // handleOutcome() must NOT be called — intent stays non-terminal
        $this->orchestration->expects($this->never())->method('handleOutcome');
    }

    /**
     * SC-03: When the attempt transitions to UNKNOWN with a wallet hold in place,
     * the wallet_hold_id remains set on the saved attempt — the hold is preserved
     * until the outcome is reconciled.
     */
    public function test_unknown_attempt_hold_preserved(): void
    {
        $attempt = $this->makeAttempt('processing', 'prov-ref-001', 'hold-001');
        $intent  = $this->makeIntent('processing');

        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('findById')->willReturn($intent);

        $this->adapter->method('queryPaymentStatus')
            ->willReturn(new PaymentStatusResult(status: PaymentAttemptStatus::Processing));

        $this->makeJob()->handle($this->attemptRepo, $this->intentRepo, $this->orchestration);

        $this->assertNotNull($this->savedAttempt);
        $this->assertSame(
            PaymentAttemptStatus::Unknown,
            $this->savedAttempt->status,
        );
        $this->assertSame(
            'hold-001',
            $this->savedAttempt->wallet_hold_id,
            'wallet_hold_id must remain set — hold preserved on UNKNOWN'
        );
    }

    /**
     * When the attempt has no provider reference, the job must transition to UNKNOWN
     * immediately (no adapter query) since the outcome cannot be determined.
     */
    public function test_no_provider_reference_transitions_to_unknown_directly(): void
    {
        $attempt = $this->makeAttempt('submitting', null); // no provider reference
        $intent  = $this->makeIntent('processing');

        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('findById')->willReturn($intent);

        // Adapter should never be called when provider reference is null
        $this->adapter->expects($this->never())->method('queryPaymentStatus');

        $this->makeJob()->handle($this->attemptRepo, $this->intentRepo, $this->orchestration);

        $this->assertNotNull($this->savedAttempt);
        $this->assertSame(PaymentAttemptStatus::Unknown, $this->savedAttempt->status);
    }
}
