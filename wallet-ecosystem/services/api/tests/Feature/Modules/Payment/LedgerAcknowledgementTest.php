<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentFulfilmentStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Events\PaymentConfirmedEvent;
use App\Modules\Payment\Domain\Events\PaymentFailedEvent;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;

/**
 * P08: Ledger Acknowledgement and Fulfilment Handoff
 *
 * Verifies that capture-accounting decoupling works correctly:
 * - Capture transaction commits first with PendingLedger status
 * - postLedgerAcknowledgement posts balanced two-line journal after capture
 * - PaymentConfirmedEvent is published AFTER journal is durable
 * - Outages and missing config are deferred without failing the capture
 */
final class LedgerAcknowledgementTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private PaymentOrchestrationService $service;

    /** @var MockObject&PaymentIntentRepositoryInterface */
    private MockObject $intentRepo;

    /** @var MockObject&PaymentAttemptRepositoryInterface */
    private MockObject $attemptRepo;

    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;

    /** @var MockObject&EventPublisherInterface */
    private MockObject $events;

    /** @var MockObject&WalletModuleContract */
    private MockObject $wallet;

    /** @var MockObject&LedgerModuleContract */
    private MockObject $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->intentRepo  = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->attemptRepo = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->config      = $this->createMock(ConfigurationServiceInterface::class);
        $this->events      = $this->createMock(EventPublisherInterface::class);
        $this->wallet      = $this->createMock(WalletModuleContract::class);
        $this->ledger      = $this->createMock(LedgerModuleContract::class);

        $this->service = new PaymentOrchestrationService(
            intentRepository:  $this->intentRepo,
            attemptRepository: $this->attemptRepo,
            purposeRegistry:   $this->createMock(BusinessPurposeResolverRegistry::class),
            wallet:            $this->wallet,
            ledger:            $this->ledger,
            config:            $this->config,
            audit:             $this->createMock(AuditServiceInterface::class),
            events:            $this->events,
            container:         $this->app,
        );
    }

    /**
     * P08 SC-01: Wallet payment — balanced journal posted, PaymentConfirmedEvent published,
     * accounting_status advances to ledger_posted.
     * Idempotency key MUST be payment:{intentId}:ack (NOT payment:{attemptId}:debit).
     */
    public function test_wallet_payment_posts_balanced_journal_and_publishes_confirmed_event(): void
    {
        $intentId  = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();
        $walletId  = (string) Uuid::uuid7();
        $journalId = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel([
            'id'                => $intentId,
            'status'            => PaymentIntentStatus::Processing,
            'accounting_status' => PaymentAccountingStatus::PendingLedger,
            'payer_actor_id'    => 'actor-1',
            'purpose_type'      => 'payment',
            'purpose_reference' => 'ref-1',
            'organisation_id'   => 'org-1',
            'currency_code'     => 'BWP',
            'amount_minor'      => 10000,
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);

        $attempt = new PaymentAttemptModel([
            'id'               => $attemptId,
            'payment_intent_id' => $intentId,
            'status'           => PaymentAttemptStatus::Processing,
            'provider_code'    => 'olekard_wallet',
            'wallet_hold_id'   => (string) Uuid::uuid7(),
            'fee_minor'        => 0,
        ]);

        $freshIntent = new PaymentIntentModel([
            'id'                => $intentId,
            'status'            => PaymentIntentStatus::Succeeded,
            'accounting_status' => PaymentAccountingStatus::PendingLedger,
            'payer_actor_id'    => 'actor-1',
            'purpose_type'      => 'payment',
            'purpose_reference' => 'ref-1',
            'organisation_id'   => 'org-1',
            'currency_code'     => 'BWP',
            'amount_minor'      => 10000,
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);

        $this->intentRepo->method('findById')->willReturnOnConsecutiveCalls($intent, $freshIntent);
        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('save')->willReturnCallback(static fn () => null);

        $this->wallet->method('findPrimaryWalletId')->willReturn($walletId);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-account-ref');
        $this->config->method('getOrDefault')->willReturnMap([
            ['payment.credit_account_BWP', null, 'org-credit-account-ref'],
            ['payment.excess_capture_tolerance_minor', 0, 0],
        ]);

        // Journal MUST use payment:{intentId}:ack and have two balanced lines
        $this->ledger->expects(self::once())->method('postEntry')
            ->with(self::callback(static function (array $r) use ($intentId): bool {
                return $r['idempotency_key'] === 'payment:'.$intentId.':ack'
                    && count($r['lines']) === 2
                    && $r['lines'][0]['direction'] === 'debit'
                    && $r['lines'][1]['direction'] === 'credit'
                    && $r['lines'][0]['amount'] === 10000
                    && $r['lines'][1]['amount'] === 10000;
            }))
            ->willReturn($journalId);

        // PaymentConfirmedEvent published AFTER journal is durable
        $this->events->expects(self::once())->method('publish')
            ->with(self::isInstanceOf(PaymentConfirmedEvent::class));

        $this->service->handleOutcome([
            'payment_intent_id'  => $intentId,
            'payment_attempt_id' => $attemptId,
            'outcome'            => 'succeeded',
            'correlation_id'     => (string) Uuid::uuid7(),
        ]);
    }

    /**
     * P08 SC-02 / AD-09 guard: no wallet hold (bank/external) → ledger skipped,
     * accounting stays pending_ledger for repair job, no PaymentConfirmedEvent.
     */
    public function test_no_wallet_hold_defers_ledger_posting(): void
    {
        $intentId  = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel([
            'id'                => $intentId,
            'status'            => PaymentIntentStatus::Processing,
            'accounting_status' => PaymentAccountingStatus::PendingLedger,
            'payer_actor_id'    => 'actor-1',
            'purpose_type'      => 'payment',
            'purpose_reference' => 'ref-1',
            'organisation_id'   => 'org-1',
            'currency_code'     => 'BWP',
            'amount_minor'      => 10000,
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);

        $attempt = new PaymentAttemptModel([
            'id'               => $attemptId,
            'payment_intent_id' => $intentId,
            'status'           => PaymentAttemptStatus::Processing,
            'provider_code'    => 'mock_bank',
            'wallet_hold_id'   => null, // NO wallet hold — external provider
            'fee_minor'        => 0,
        ]);

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('save')->willReturnCallback(static fn () => null);
        $this->config->method('getOrDefault')->willReturnMap([
            ['payment.excess_capture_tolerance_minor', 0, 0],
        ]);

        // No journal should be posted for external/bank payments (AD-09 deferred)
        $this->ledger->expects(self::never())->method('postEntry');

        // No PaymentConfirmedEvent — accounting is deferred
        $this->events->expects(self::never())->method('publish');

        $this->service->handleOutcome([
            'payment_intent_id'  => $intentId,
            'payment_attempt_id' => $attemptId,
            'outcome'            => 'succeeded',
            'correlation_id'     => (string) Uuid::uuid7(),
        ]);
    }

    /**
     * P08 SC-03: Ledger outage — postEntry throws; capture succeeds, accounting stays
     * pending_ledger, no exception rethrown to caller (repair job handles it).
     */
    public function test_ledger_outage_defers_accounting_without_failing_capture(): void
    {
        $intentId  = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();
        $walletId  = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel([
            'id'                => $intentId,
            'status'            => PaymentIntentStatus::Processing,
            'accounting_status' => PaymentAccountingStatus::PendingLedger,
            'payer_actor_id'    => 'actor-1',
            'purpose_type'      => 'payment',
            'purpose_reference' => 'ref-1',
            'organisation_id'   => 'org-1',
            'currency_code'     => 'BWP',
            'amount_minor'      => 5000,
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);

        $attempt = new PaymentAttemptModel([
            'id'               => $attemptId,
            'payment_intent_id' => $intentId,
            'status'           => PaymentAttemptStatus::Processing,
            'provider_code'    => 'olekard_wallet',
            'wallet_hold_id'   => (string) Uuid::uuid7(),
            'fee_minor'        => 0,
        ]);

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('save')->willReturnCallback(static fn () => null);

        $this->wallet->method('findPrimaryWalletId')->willReturn($walletId);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-account-ref');
        $this->config->method('getOrDefault')->willReturnMap([
            ['payment.credit_account_BWP', null, 'org-credit-account-ref'],
            ['payment.excess_capture_tolerance_minor', 0, 0],
        ]);

        // Ledger service is unavailable
        $this->ledger->method('postEntry')->willThrowException(
            new \RuntimeException('Ledger service unavailable')
        );

        // Capture must NOT fail even when ledger is down
        $this->events->expects(self::never())->method('publish');

        // Must not throw — failure is deferred to repair job
        $this->service->handleOutcome([
            'payment_intent_id'  => $intentId,
            'payment_attempt_id' => $attemptId,
            'outcome'            => 'succeeded',
            'correlation_id'     => (string) Uuid::uuid7(),
        ]);
    }

    /**
     * P08 SC-04 / AD-08 guard: missing credit account config → accounting deferred,
     * capture still succeeds, no exception rethrown to caller.
     */
    public function test_missing_credit_account_config_defers_accounting(): void
    {
        $intentId  = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();
        $walletId  = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel([
            'id'                => $intentId,
            'status'            => PaymentIntentStatus::Processing,
            'accounting_status' => PaymentAccountingStatus::PendingLedger,
            'payer_actor_id'    => 'actor-1',
            'purpose_type'      => 'payment',
            'purpose_reference' => 'ref-1',
            'organisation_id'   => 'org-1',
            'currency_code'     => 'BWP',
            'amount_minor'      => 8000,
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);

        $attempt = new PaymentAttemptModel([
            'id'               => $attemptId,
            'payment_intent_id' => $intentId,
            'status'           => PaymentAttemptStatus::Processing,
            'provider_code'    => 'olekard_wallet',
            'wallet_hold_id'   => (string) Uuid::uuid7(),
            'fee_minor'        => 0,
        ]);

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('save')->willReturnCallback(static fn () => null);

        $this->wallet->method('findPrimaryWalletId')->willReturn($walletId);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-account-ref');
        // Config returns null for credit account — AD-08 not yet configured
        $this->config->method('getOrDefault')->willReturnMap([
            ['payment.credit_account_BWP', null, null],
            ['payment.excess_capture_tolerance_minor', 0, 0],
        ]);

        $this->ledger->expects(self::never())->method('postEntry');
        $this->events->expects(self::never())->method('publish');

        // Must not throw — deferred to repair job pending AD-08 resolution
        $this->service->handleOutcome([
            'payment_intent_id'  => $intentId,
            'payment_attempt_id' => $attemptId,
            'outcome'            => 'succeeded',
            'correlation_id'     => (string) Uuid::uuid7(),
        ]);
    }

    /**
     * P08 / P06 Guard A: idempotent success replay (same provider ref arriving twice)
     * — no double posting, no duplicate event.
     */
    public function test_idempotent_success_replay_does_not_double_post(): void
    {
        $intentId       = (string) Uuid::uuid7();
        $attemptId      = (string) Uuid::uuid7();
        $providerRef    = 'PRV-REF-001';

        $intent = new PaymentIntentModel([
            'id'                => $intentId,
            'status'            => PaymentIntentStatus::Succeeded,
            'accounting_status' => PaymentAccountingStatus::LedgerPosted,
            'payer_actor_id'    => 'actor-1',
            'currency_code'     => 'BWP',
            'amount_minor'      => 10000,
        ]);

        // Attempt already Succeeded with same provider reference
        $attempt = new PaymentAttemptModel([
            'id'                  => $attemptId,
            'payment_intent_id'   => $intentId,
            'status'              => PaymentAttemptStatus::Succeeded,
            'provider_reference_id' => $providerRef,
            'wallet_hold_id'      => (string) Uuid::uuid7(),
        ]);

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);

        // No save, no ledger posting, no event on idempotent replay
        $this->intentRepo->expects(self::never())->method('save');
        $this->ledger->expects(self::never())->method('postEntry');
        $this->events->expects(self::never())->method('publish');

        // Second call with same succeeded outcome and same provider ref — must be no-op
        $this->service->handleOutcome([
            'payment_intent_id'   => $intentId,
            'payment_attempt_id'  => $attemptId,
            'outcome'             => 'succeeded',
            'provider_reference_id' => $providerRef,
            'correlation_id'      => (string) Uuid::uuid7(),
        ]);
    }

    /**
     * P08 US3: PaymentConfirmedEvent allocation_reference must equal the intent ID (NOT attempt ID)
     * so that consuming modules can use it as an idempotency key for entitlement allocation.
     */
    public function test_confirmed_event_carries_intent_id_as_allocation_reference(): void
    {
        $intentId  = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();
        $walletId  = (string) Uuid::uuid7();
        $journalId = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel([
            'id'                => $intentId,
            'status'            => PaymentIntentStatus::Processing,
            'accounting_status' => PaymentAccountingStatus::PendingLedger,
            'payer_actor_id'    => 'actor-1',
            'purpose_type'      => 'payment',
            'purpose_reference' => 'ref-1',
            'organisation_id'   => 'org-1',
            'currency_code'     => 'BWP',
            'amount_minor'      => 6000,
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);

        $freshIntent = new PaymentIntentModel([
            'id'                => $intentId,
            'status'            => PaymentIntentStatus::Succeeded,
            'accounting_status' => PaymentAccountingStatus::PendingLedger,
            'payer_actor_id'    => 'actor-1',
            'purpose_type'      => 'payment',
            'purpose_reference' => 'ref-1',
            'organisation_id'   => 'org-1',
            'currency_code'     => 'BWP',
            'amount_minor'      => 6000,
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);

        $attempt = new PaymentAttemptModel([
            'id'               => $attemptId,
            'payment_intent_id' => $intentId,
            'status'           => PaymentAttemptStatus::Processing,
            'provider_code'    => 'olekard_wallet',
            'wallet_hold_id'   => (string) Uuid::uuid7(),
            'fee_minor'        => 0,
        ]);

        $this->intentRepo->method('findById')->willReturnOnConsecutiveCalls($intent, $freshIntent);
        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('save')->willReturnCallback(static fn () => null);

        $this->wallet->method('findPrimaryWalletId')->willReturn($walletId);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');
        $this->config->method('getOrDefault')->willReturnMap([
            ['payment.credit_account_BWP', null, 'org-credit-ref'],
            ['payment.excess_capture_tolerance_minor', 0, 0],
        ]);
        $this->ledger->method('postEntry')->willReturn($journalId);

        // Capture the published event to inspect allocation_reference
        $publishedEvent = null;
        $this->events->method('publish')->willReturnCallback(
            static function (object $event) use (&$publishedEvent): void {
                $publishedEvent = $event;
            }
        );

        $this->service->handleOutcome([
            'payment_intent_id'  => $intentId,
            'payment_attempt_id' => $attemptId,
            'outcome'            => 'succeeded',
            'correlation_id'     => (string) Uuid::uuid7(),
        ]);

        self::assertInstanceOf(PaymentConfirmedEvent::class, $publishedEvent);
        $payload = $publishedEvent->toArray();
        // allocation_reference MUST be the intent ID, NOT the attempt ID
        self::assertSame($intentId, $payload['allocation_reference']);
        self::assertNotSame($attemptId, $payload['allocation_reference']);
    }

    /**
     * P08 / failed outcome: accounting_status = ledger_failed, PaymentFailedEvent published,
     * no journal posted.
     */
    public function test_failed_outcome_sets_ledger_failed_and_publishes_failed_event(): void
    {
        $intentId  = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel([
            'id'                => $intentId,
            'status'            => PaymentIntentStatus::Processing,
            'accounting_status' => PaymentAccountingStatus::PendingLedger,
            'fulfilment_status' => PaymentFulfilmentStatus::Pending,
            'payer_actor_id'    => 'actor-1',
            'purpose_type'      => 'payment',
            'purpose_reference' => 'ref-1',
            'organisation_id'   => 'org-1',
            'currency_code'     => 'BWP',
            'amount_minor'      => 10000,
            'correlation_id'    => (string) Uuid::uuid7(),
        ]);

        $attempt = new PaymentAttemptModel([
            'id'               => $attemptId,
            'payment_intent_id' => $intentId,
            'status'           => PaymentAttemptStatus::Processing,
            'provider_code'    => 'olekard_wallet',
        ]);

        $this->intentRepo->method('findById')->willReturn($intent);
        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->intentRepo->method('save')
            ->willReturnCallback(static function (PaymentIntentModel $saved): void {
                // Verify failed path sets ledger_failed
                if ($saved->accounting_status !== null) {
                    \PHPUnit\Framework\Assert::assertSame(
                        PaymentAccountingStatus::LedgerFailed,
                        $saved->accounting_status,
                    );
                }
            });

        $this->ledger->expects(self::never())->method('postEntry');

        $this->events->expects(self::once())->method('publish')
            ->with(self::isInstanceOf(PaymentFailedEvent::class));

        $this->service->handleOutcome([
            'payment_intent_id'  => $intentId,
            'payment_attempt_id' => $attemptId,
            'outcome'            => 'failed',
            'failure_reason'     => 'insufficient_funds',
            'correlation_id'     => (string) Uuid::uuid7(),
        ]);
    }
}
