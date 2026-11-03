<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Jobs\OutboxDispatcherJob;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Infrastructure\EventBus\EventBusDriverInterface;
use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/** Real Wallet -> Ledger -> Payment integration on the isolated PostgreSQL schema. */
final class WalletCapturePaymentIntegrationTest extends TestCase
{
    /** @var array{owner:string,wallet:string,pocket:string,intent:string,attempt:string,hold:string,organisation:string,credit:string} */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'wallet.capture.test_only_enabled' => true,
            'wallet.capture.test_destination_accounts.BWP' => 'p08-test-destination:'.Uuid::uuid7(),
        ]);
        $this->fixture = $this->createPaymentFixture();
    }

    protected function tearDown(): void
    {
        DB::connection('platform')->statement('DROP TRIGGER IF EXISTS p08_fail_payment_capture_persist ON payment.payment_attempts');
        DB::connection('platform')->statement('DROP FUNCTION IF EXISTS payment.p08_fail_payment_capture_persist()');
        DB::connection('platform')->statement('DROP TRIGGER IF EXISTS p08_fail_payment_confirmation_outbox ON platform.outbox_events');
        DB::connection('platform')->statement('DROP FUNCTION IF EXISTS platform.p08_fail_payment_confirmation_outbox()');
        DB::connection('platform')->statement('DROP TRIGGER IF EXISTS p08_fail_wallet_capture_record ON wallet.wallet_capture_records');
        DB::connection('platform')->statement('DROP FUNCTION IF EXISTS wallet.p08_fail_wallet_capture_record()');
        parent::tearDown();
    }

    public function test_wallet_capture_evidence_is_persisted_and_confirmation_outbox_is_atomic_and_replay_safe(): void
    {
        $service = app(PaymentOrchestrationService::class);
        $this->failPaymentCapturePersistenceOnce();

        $service->handleOutcome($this->successfulOutcome());
        $this->assertWalletAndLedgerCommittedButPaymentStillPending();

        DB::connection('platform')->statement('DROP TRIGGER p08_fail_payment_capture_persist ON payment.payment_attempts');
        DB::connection('platform')->statement('DROP FUNCTION payment.p08_fail_payment_capture_persist()');
        $service->repairAccountingForIntent($this->fixture['intent'], (string) Uuid::uuid7());

        $this->assertPaymentConfirmationCommitted();
        $service->repairAccountingForIntent($this->fixture['intent'], (string) Uuid::uuid7());
        $this->assertSame(1, DB::connection('platform')->table('ledger.journals')
            ->where('idempotency_key', 'payment:'.$this->fixture['intent'].':ack')->count());
        $this->assertSame(1, DB::connection('platform')->table('wallet.wallet_capture_records')
            ->where('hold_id', $this->fixture['hold'])->count());
        $this->assertSame(1, DB::connection('platform')->table('platform.outbox_events')
            ->where('event_name', 'payment.confirmed')->where('aggregate_id', $this->fixture['intent'])->count());
    }

    public function test_payment_outbox_failure_rolls_back_payment_references_then_recovery_uses_same_capture(): void
    {
        $this->failPaymentConfirmationOutbox();
        app(PaymentOrchestrationService::class)->handleOutcome($this->successfulOutcome());

        $intent = DB::connection('payment')->table('payment.payment_intents')->where('id', $this->fixture['intent'])->first();
        $attempt = DB::connection('payment')->table('payment.payment_attempts')->where('id', $this->fixture['attempt'])->first();
        self::assertNotNull($intent);
        self::assertNotNull($attempt);
        $this->assertSame(PaymentAccountingStatus::PendingLedger->value, $intent->accounting_status);
        $this->assertNull($attempt->ledger_journal_id);
        $this->assertSame(1, DB::connection('platform')->table('wallet.wallet_capture_records')
            ->where('hold_id', $this->fixture['hold'])->count());
        $this->assertSame(1, DB::connection('platform')->table('ledger.journals')
            ->where('idempotency_key', 'payment:'.$this->fixture['intent'].':ack')->count());
        $this->assertSame(0, DB::connection('platform')->table('platform.outbox_events')
            ->where('event_name', 'payment.confirmed')->where('aggregate_id', $this->fixture['intent'])->count());

        DB::connection('platform')->statement('DROP TRIGGER p08_fail_payment_confirmation_outbox ON platform.outbox_events');
        DB::connection('platform')->statement('DROP FUNCTION platform.p08_fail_payment_confirmation_outbox()');
        app(PaymentOrchestrationService::class)->repairAccountingForIntent($this->fixture['intent'], (string) Uuid::uuid7());
        $this->assertPaymentConfirmationCommitted();
    }

    public function test_wallet_record_failure_after_ledger_commit_recovers_same_journal_without_duplicate_debit(): void
    {
        $this->failWalletCaptureRecordOnce();
        app(PaymentOrchestrationService::class)->handleOutcome($this->successfulOutcome());

        $hold = DB::connection('wallet')->table('wallet.wallet_holds')->where('id', $this->fixture['hold'])->first();
        $intent = DB::connection('payment')->table('payment.payment_intents')->where('id', $this->fixture['intent'])->first();
        self::assertNotNull($hold);
        self::assertNotNull($intent);
        $this->assertSame('active', $hold->status);
        $this->assertSame(PaymentAccountingStatus::PendingLedger->value, $intent->accounting_status);
        $this->assertSame(1, DB::connection('platform')->table('ledger.journals')
            ->where('idempotency_key', 'payment:'.$this->fixture['intent'].':ack')->count());
        $this->assertSame(0, DB::connection('platform')->table('wallet.wallet_capture_records')
            ->where('hold_id', $this->fixture['hold'])->count());

        DB::connection('platform')->statement('DROP TRIGGER p08_fail_wallet_capture_record ON wallet.wallet_capture_records');
        DB::connection('platform')->statement('DROP FUNCTION wallet.p08_fail_wallet_capture_record()');
        app(PaymentOrchestrationService::class)->repairAccountingForIntent($this->fixture['intent'], (string) Uuid::uuid7());

        $this->assertPaymentConfirmationCommitted();
        $this->assertSame('captured', DB::connection('wallet')->table('wallet.wallet_holds')
            ->where('id', $this->fixture['hold'])->value('status'));
        $this->assertSame(1, DB::connection('platform')->table('ledger.journals')
            ->where('idempotency_key', 'payment:'.$this->fixture['intent'].':ack')->count());
        $this->assertSame(1, DB::connection('platform')->table('wallet.wallet_capture_records')
            ->where('hold_id', $this->fixture['hold'])->count());
    }

    public function test_repair_exhaustion_is_audited_and_creates_one_accounting_reconciliation_exception(): void
    {
        DB::connection('payment')->table('payment.payment_intents')
            ->where('id', $this->fixture['intent'])
            ->update(['accounting_repair_attempts' => 5]);
        DB::connection('payment')->table('payment.payment_attempts')
            ->where('id', $this->fixture['attempt'])
            ->update(['status' => 'succeeded']);

        $service = app(PaymentOrchestrationService::class);
        $service->repairAccountingForIntent($this->fixture['intent'], (string) Uuid::uuid7());
        $service->repairAccountingForIntent($this->fixture['intent'], (string) Uuid::uuid7());

        $this->assertSame('ledger_failed', DB::connection('payment')->table('payment.payment_intents')
            ->where('id', $this->fixture['intent'])->value('accounting_status'));
        $this->assertSame(1, DB::connection('payment')->table('payment.reconciliation_exceptions')
            ->where('payment_intent_id', $this->fixture['intent'])
            ->where('exception_type', 'accounting_failure')->count());
        $exception = DB::connection('payment')->table('payment.reconciliation_exceptions')
            ->where('payment_intent_id', $this->fixture['intent'])->first();
        self::assertNotNull($exception);
        $this->assertSame($this->fixture['attempt'], $exception->payment_attempt_id);
        $this->assertSame('olekard_wallet', $exception->provider_code);
        $this->assertSame(0, DB::connection('platform')->table('ledger.journals')
            ->where('idempotency_key', 'payment:'.$this->fixture['intent'].':ack')->count());
        $this->assertSame('active', DB::connection('wallet')->table('wallet.wallet_holds')
            ->where('id', $this->fixture['hold'])->value('status'));
    }

    public function test_repair_exhaustion_stays_pending_without_succeeded_attempt_evidence(): void
    {
        DB::connection('payment')->table('payment.payment_intents')
            ->where('id', $this->fixture['intent'])
            ->update(['accounting_repair_attempts' => 5]);

        try {
            app(PaymentOrchestrationService::class)->repairAccountingForIntent(
                $this->fixture['intent'], (string) Uuid::uuid7(),
            );
            $this->fail('Expected repair exhaustion to require authoritative succeeded-attempt evidence.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('succeeded attempt', $exception->getMessage());
        }

        $this->assertSame('pending_ledger', DB::connection('payment')->table('payment.payment_intents')
            ->where('id', $this->fixture['intent'])->value('accounting_status'));
        $this->assertSame(0, DB::connection('payment')->table('payment.reconciliation_exceptions')
            ->where('payment_intent_id', $this->fixture['intent'])->count());
    }

    public function test_durable_confirmation_outbox_delivers_after_payment_commit_when_dispatch_is_retried(): void
    {
        app(PaymentOrchestrationService::class)->handleOutcome($this->successfulOutcome());

        $event = DB::connection('platform')->table('platform.outbox_events')
            ->where('event_name', 'payment.confirmed')
            ->where('aggregate_id', $this->fixture['intent'])
            ->first();
        self::assertNotNull($event);
        $this->assertSame('pending', $event->status);
        $this->assertSame(0, DB::connection('notification')->table('notification.notifications')
            ->where('source_event_id', $event->id)->count());

        // No delivery is attempted until after the Payment transaction has committed.
        app(OutboxDispatcherJob::class)->handle(
            app(EventBusDriverInterface::class),
            app(AuditServiceInterface::class),
        );

        $this->assertSame('delivered', DB::connection('platform')->table('platform.outbox_events')
            ->where('id', $event->id)->value('status'));
        $this->assertSame(1, DB::connection('notification')->table('notification.notifications')
            ->where('source_event_id', $event->id)
            ->where('notification_type', 'payment_confirmed')->count());
    }

    /** @return array{payment_intent_id:string,payment_attempt_id:string,outcome:'succeeded',correlation_id:string} */
    private function successfulOutcome(): array
    {
        return [
            'payment_intent_id' => $this->fixture['intent'],
            'payment_attempt_id' => $this->fixture['attempt'],
            'outcome' => 'succeeded',
            'correlation_id' => (string) Uuid::uuid7(),
        ];
    }

    /** @return array{owner:string,wallet:string,pocket:string,intent:string,attempt:string,hold:string,organisation:string,credit:string} */
    private function createPaymentFixture(): array
    {
        $owner = (string) Uuid::uuid7();
        $wallet = (string) Uuid::uuid7();
        $pocket = (string) Uuid::uuid7();
        $intent = (string) Uuid::uuid7();
        $attempt = (string) Uuid::uuid7();
        $organisation = (string) Uuid::uuid7();
        $purposeReference = (string) Uuid::uuid7();
        $correlation = (string) Uuid::uuid7();
        $credit = 'p08-integration-destination:'.$intent;
        DB::connection('identity')->table('identity.users')->insert([
            'id' => $owner, 'status' => 'active', 'actor_type' => 'CUSTOMER', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('wallet')->table('wallet.wallets')->insert([
            'id' => $wallet, 'wallet_reference' => 'P08-'.strtoupper(substr(str_replace('-', '', $wallet), 0, 18)),
            'owner_id' => $owner, 'status' => 'active', 'version' => 1, 'created_by' => $owner,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('wallet')->table('wallet.currency_pockets')->insert([
            'id' => $pocket, 'wallet_id' => $wallet, 'currency_code' => 'BWP',
            'ledger_account_reference' => 'p08-wallet-pocket:'.$pocket, 'status' => 'active',
            'created_by' => $owner, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(LedgerModuleContract::class)->postEntry([
            'idempotency_key' => 'p08-wallet-fund:'.$intent,
            'entry_type' => 'credit', 'currency_code' => 'BWP', 'transaction_reference' => $wallet,
            'correlation_id' => $correlation, 'actor_id' => $owner,
            'lines' => [
                ['ledger_account_reference' => 'p08-wallet-funding:'.$intent, 'direction' => 'debit', 'amount' => 3500],
                ['ledger_account_reference' => 'p08-wallet-pocket:'.$pocket, 'direction' => 'credit', 'amount' => 3500],
            ],
        ]);
        $attemptHold = app(WalletHoldServiceContract::class)->place(
            $wallet, 'BWP', 1250, $owner, $correlation, $attempt,
            expiresAt: now()->addHour()->toDateTimeImmutable(), organisationId: $organisation,
        );

        $provider = DB::connection('payment')->table('payment.payment_providers')->where('code', 'olekard_wallet')->value('id');
        if ($provider === null) {
            throw new \RuntimeException('OleKard Wallet provider seed is unavailable.');
        }
        $accountId = (string) Uuid::uuid7();
        DB::connection('payment')->table('payment.payment_provider_accounts')->insert([
            'id' => $accountId, 'provider_id' => $provider, 'merchant_account_reference' => 'p08-test-'.$accountId,
            'supported_currencies' => json_encode(['BWP'], JSON_THROW_ON_ERROR),
            'supported_payment_methods' => json_encode(['wallet'], JSON_THROW_ON_ERROR),
            'min_amount_minor' => 1, 'max_amount_minor' => 100000,
            'enabled_organisations' => json_encode([], JSON_THROW_ON_ERROR),
            'enabled_modules' => json_encode([], JSON_THROW_ON_ERROR),
            'country_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
            'capability_flags' => json_encode([], JSON_THROW_ON_ERROR),
            'fee_rule_ids' => json_encode([], JSON_THROW_ON_ERROR),
            'daily_limit_minor' => null, 'is_active' => false, 'config_version' => 1,
            'enabled_purpose_types' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id' => $intent, 'reference' => 'P08'.strtoupper(substr(str_replace('-', '', $intent), 0, 20)),
            // This P08 suite exercises generic Wallet/Payment recovery. Keep its
            // purpose outside P25 so the real Events consumer does not receive a
            // confirmation with no corresponding authoritative Events order.
            'purpose_type' => 'bill', 'purpose_reference' => $purposeReference,
            'organisation_id' => $organisation, 'payer_actor_id' => $owner, 'currency_code' => 'BWP',
            'amount_minor' => 1250, 'status' => 'processing', 'accounting_status' => 'pending_ledger',
            'fulfilment_status' => 'pending', 'version' => 1, 'fee_quote_minor' => 0,
            'idempotency_key' => 'p08-test-intent:'.$intent, 'correlation_id' => $correlation,
            'metadata' => json_encode([], JSON_THROW_ON_ERROR), 'expires_at' => now()->addHour(),
            'accounting_repair_attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('payment')->table('payment.payment_attempts')->insert([
            'id' => $attempt, 'payment_intent_id' => $intent, 'provider_code' => 'olekard_wallet',
            'provider_account_id' => $accountId, 'status' => 'submitting',
            'idempotency_key' => 'p08-test-attempt:'.$attempt, 'operation_id' => (string) Uuid::uuid7(),
            'wallet_hold_id' => $attemptHold->id, 'fee_minor' => 0, 'initiated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('owner', 'wallet', 'pocket', 'intent', 'attempt', 'organisation', 'credit')
            + ['hold' => $attemptHold->id];
    }

    private function assertWalletAndLedgerCommittedButPaymentStillPending(): void
    {
        $intent = DB::connection('payment')->table('payment.payment_intents')->where('id', $this->fixture['intent'])->first();
        $attempt = DB::connection('payment')->table('payment.payment_attempts')->where('id', $this->fixture['attempt'])->first();
        $hold = DB::connection('wallet')->table('wallet.wallet_holds')->where('id', $this->fixture['hold'])->first();
        self::assertNotNull($intent);
        self::assertNotNull($attempt);
        self::assertNotNull($hold);
        $this->assertSame('pending_ledger', $intent->accounting_status);
        $this->assertNull($attempt->ledger_journal_id);
        $this->assertNull($attempt->captured_amount_minor);
        $this->assertSame('captured', $hold->status);
        $this->assertSame(1, DB::connection('platform')->table('ledger.journals')
            ->where('idempotency_key', 'payment:'.$this->fixture['intent'].':ack')->count());
    }

    private function assertPaymentConfirmationCommitted(): void
    {
        $intent = DB::connection('payment')->table('payment.payment_intents')->where('id', $this->fixture['intent'])->first();
        $attempt = DB::connection('payment')->table('payment.payment_attempts')->where('id', $this->fixture['attempt'])->first();
        self::assertNotNull($intent);
        self::assertNotNull($attempt);
        $this->assertSame(PaymentAccountingStatus::LedgerPosted->value, $intent->accounting_status);
        $this->assertSame(1250, (int) $attempt->captured_amount_minor);
        $this->assertSame('BWP', trim($attempt->captured_currency_code));
        $this->assertNotNull($attempt->capture_evidence_reference);
        $this->assertSame(1, DB::connection('platform')->table('platform.outbox_events')
            ->where('event_name', 'payment.confirmed')->where('aggregate_id', $this->fixture['intent'])->count());
    }

    private function failPaymentCapturePersistenceOnce(): void
    {
        DB::connection('platform')->unprepared(<<<'SQL'
            CREATE FUNCTION payment.p08_fail_payment_capture_persist() RETURNS trigger AS $body$
            BEGIN
                IF NEW.ledger_journal_id IS NOT NULL THEN
                    RAISE EXCEPTION 'P08 fault: Payment evidence persistence';
                END IF;
                RETURN NEW;
            END;
            $body$ LANGUAGE plpgsql
            SQL
        );
        DB::connection('platform')->statement(<<<'SQL'
            CREATE TRIGGER p08_fail_payment_capture_persist
            BEFORE UPDATE ON payment.payment_attempts
            FOR EACH ROW EXECUTE FUNCTION payment.p08_fail_payment_capture_persist()
            SQL
        );
    }

    private function failPaymentConfirmationOutbox(): void
    {
        DB::connection('platform')->unprepared(<<<'SQL'
            CREATE FUNCTION platform.p08_fail_payment_confirmation_outbox() RETURNS trigger AS $body$
            BEGIN
                IF NEW.event_name = 'payment.confirmed' THEN
                    RAISE EXCEPTION 'P08 fault: Payment confirmation outbox insert';
                END IF;
                RETURN NEW;
            END;
            $body$ LANGUAGE plpgsql
            SQL
        );
        DB::connection('platform')->statement(<<<'SQL'
            CREATE TRIGGER p08_fail_payment_confirmation_outbox
            BEFORE INSERT ON platform.outbox_events
            FOR EACH ROW EXECUTE FUNCTION platform.p08_fail_payment_confirmation_outbox()
            SQL
        );
    }

    private function failWalletCaptureRecordOnce(): void
    {
        DB::connection('platform')->unprepared(<<<'SQL'
            CREATE FUNCTION wallet.p08_fail_wallet_capture_record() RETURNS trigger AS $body$
            BEGIN
                RAISE EXCEPTION 'P08 fault: Wallet capture record persistence';
            END;
            $body$ LANGUAGE plpgsql
            SQL
        );
        DB::connection('platform')->statement(<<<'SQL'
            CREATE TRIGGER p08_fail_wallet_capture_record
            BEFORE INSERT ON wallet.wallet_capture_records
            FOR EACH ROW EXECUTE FUNCTION wallet.p08_fail_wallet_capture_record()
            SQL
        );
    }
}
