<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Identity\Domain\Contracts\ServiceActor;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Ledger\Domain\ValueObjects\PostedJournalEvidence;
use App\Modules\Payment\Application\Services\PaymentConfirmationEvidenceService;
use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Payment\Domain\Enums\PaymentFulfilmentStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/** Database-backed P08 acceptance against the isolated PostgreSQL payment schema. */
final class PaymentFulfilmentAcknowledgementDatabaseTest extends TestCase
{
    private string $intentId;

    private string $attemptId;

    private string $accountId;

    private string $organisationId;

    private string $purposeReference;

    private string $journalId;

    private string $captureReference;

    private PaymentConfirmationEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->intentId = (string) Uuid::uuid7();
        $this->attemptId = (string) Uuid::uuid7();
        $this->accountId = (string) Uuid::uuid7();
        $this->organisationId = (string) Uuid::uuid7();
        $this->purposeReference = (string) Uuid::uuid7();
        $this->journalId = (string) Uuid::uuid7();
        $this->captureReference = (string) Uuid::uuid7();

        $provider = DB::connection('payment')->table('payment.payment_providers')
            ->where('code', 'olekard_wallet')->first(['id']);
        self::assertNotNull($provider, 'The project provider migration must have seeded OleKard Wallet.');

        DB::connection('payment')->table('payment.payment_provider_accounts')->insert([
            'id' => $this->accountId,
            'provider_id' => $provider->id,
            'merchant_account_reference' => 'p08-test-'.$this->accountId,
            'supported_currencies' => json_encode(['BWP'], JSON_THROW_ON_ERROR),
            'supported_payment_methods' => json_encode(['wallet'], JSON_THROW_ON_ERROR),
            'min_amount_minor' => 1,
            'max_amount_minor' => 1000000,
            'enabled_organisations' => json_encode([], JSON_THROW_ON_ERROR),
            'enabled_modules' => json_encode([], JSON_THROW_ON_ERROR),
            'country_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
            'capability_flags' => json_encode([], JSON_THROW_ON_ERROR),
            'fee_rule_ids' => json_encode([], JSON_THROW_ON_ERROR),
            'daily_limit_minor' => null,
            'is_active' => false,
            'config_version' => 1,
            'enabled_purpose_types' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id' => $this->intentId,
            'reference' => 'P08'.strtoupper(substr(str_replace('-', '', $this->intentId), 0, 20)),
            'purpose_type' => 'paid_event_ticket',
            'purpose_reference' => $this->purposeReference,
            'organisation_id' => $this->organisationId,
            'payer_actor_id' => (string) Uuid::uuid7(),
            'currency_code' => 'BWP',
            'amount_minor' => 12500,
            'status' => PaymentIntentStatus::Succeeded->value,
            'accounting_status' => PaymentAccountingStatus::LedgerPosted->value,
            'fulfilment_status' => PaymentFulfilmentStatus::Pending->value,
            'version' => 1,
            'fee_quote_minor' => 0,
            'idempotency_key' => 'p08-test-'.$this->intentId,
            'correlation_id' => (string) Uuid::uuid7(),
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'expires_at' => now()->addHour(),
            'accounting_repair_attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('payment')->table('payment.payment_attempts')->insert([
            'id' => $this->attemptId,
            'payment_intent_id' => $this->intentId,
            'provider_code' => 'olekard_wallet',
            'provider_account_id' => $this->accountId,
            'status' => 'succeeded',
            'idempotency_key' => 'p08-attempt-'.$this->attemptId,
            'operation_id' => (string) Uuid::uuid7(),
            'fee_minor' => 0,
            'initiated_at' => now()->subMinute(),
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'captured_amount_minor' => null,
            'captured_currency_code' => null,
            'capture_evidence_reference' => null,
            'ledger_journal_id' => null,
            'ledger_debit_account_reference' => null,
            'ledger_credit_account_reference' => null,
        ]);

        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('findPostedJournalEvidence')->with('payment:'.$this->intentId.':ack')
            ->willReturn(new PostedJournalEvidence(
                $this->journalId,
                'posted',
                'debit',
                $this->intentId,
                'payment:'.$this->intentId.':ack',
                'BWP',
                [
                    ['account_reference' => 'test-debit-account', 'direction' => 'debit', 'amount_minor' => 12500, 'currency_code' => 'BWP'],
                    ['account_reference' => 'test-credit-account', 'direction' => 'credit', 'amount_minor' => 12500, 'currency_code' => 'BWP'],
                ],
            ));
        $this->service = new PaymentConfirmationEvidenceService($ledger);
    }

    protected function tearDown(): void
    {
        DB::connection('payment')->table('payment.fulfilment_acknowledgements')->where('payment_intent_id', $this->intentId)->delete();
        DB::connection('payment')->table('payment.payment_attempts')->where('id', $this->attemptId)->delete();
        DB::connection('payment')->table('payment.payment_intents')->where('id', $this->intentId)->delete();
        DB::connection('payment')->table('payment.payment_provider_accounts')->where('id', $this->accountId)->delete();

        parent::tearDown();
    }

    public function test_nullable_capture_and_journal_fields_persist_and_acknowledgement_replay_is_idempotent(): void
    {
        $attempt = DB::connection('payment')->table('payment.payment_attempts')->where('id', $this->attemptId)->first();
        self::assertNotNull($attempt);
        self::assertNull($attempt->captured_amount_minor);
        self::assertNull($attempt->captured_currency_code);
        self::assertNull($attempt->capture_evidence_reference);
        self::assertNull($attempt->ledger_journal_id);

        $this->persistCaptureEvidence();

        $attempt = DB::connection('payment')->table('payment.payment_attempts')->where('id', $this->attemptId)->first();
        self::assertNotNull($attempt);
        self::assertSame(12500, $attempt->captured_amount_minor);
        self::assertSame('BWP', trim($attempt->captured_currency_code));
        self::assertSame($this->captureReference, $attempt->capture_evidence_reference);
        self::assertSame($this->journalId, $attempt->ledger_journal_id);

        $actor = $this->eventsActor();
        $this->service->acknowledgeFulfilment(
            $actor, $this->intentId, $this->organisationId, 'paid_event_ticket', $this->purposeReference,
            'ticket-order:'.$this->purposeReference, 'ack:'.$this->intentId,
        );
        $this->service->acknowledgeFulfilment(
            $actor, $this->intentId, $this->organisationId, 'paid_event_ticket', $this->purposeReference,
            'ticket-order:'.$this->purposeReference, 'ack:'.$this->intentId,
        );

        self::assertSame(1, DB::connection('payment')->table('payment.fulfilment_acknowledgements')
            ->where('payment_intent_id', $this->intentId)->count());
        self::assertSame(PaymentFulfilmentStatus::Fulfilled->value, DB::connection('payment')
            ->table('payment.payment_intents')->where('id', $this->intentId)->value('fulfilment_status'));
    }

    public function test_conflicting_acknowledgement_is_denied_without_overwriting_durable_record(): void
    {
        $this->persistCaptureEvidence();
        $actor = $this->eventsActor();
        $this->service->acknowledgeFulfilment(
            $actor, $this->intentId, $this->organisationId, 'paid_event_ticket', $this->purposeReference,
            'ticket-order:'.$this->purposeReference, 'ack:'.$this->intentId,
        );

        try {
            $this->service->acknowledgeFulfilment(
                $actor, $this->intentId, $this->organisationId, 'paid_event_ticket', $this->purposeReference,
                'different-fulfilment', 'different-key',
            );
            self::fail('A conflicting acknowledgement must be rejected.');
        } catch (\DomainException $exception) {
            self::assertSame('Conflicting fulfilment acknowledgement already exists.', $exception->getMessage());
        }

        self::assertSame(1, DB::connection('payment')->table('payment.fulfilment_acknowledgements')
            ->where('payment_intent_id', $this->intentId)->count());
        self::assertSame('ticket-order:'.$this->purposeReference, DB::connection('payment')
            ->table('payment.fulfilment_acknowledgements')->where('payment_intent_id', $this->intentId)->value('fulfilment_reference'));
    }

    public function test_concurrent_postgres_sessions_acknowledge_the_same_reference_once(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'The repository test PHP image must provide pcntl for process concurrency tests.');

        $this->persistCaptureEvidence();
        $actor = $this->eventsActor();
        $service = $this->service;
        $intentId = $this->intentId;
        $organisationId = $this->organisationId;
        $purposeReference = $this->purposeReference;
        DB::purge('payment');

        $sockets = [];
        for ($index = 0; $index < 2; $index++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($pair);
            [$parentSocket, $childSocket] = $pair;
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'Could not create a separate database worker process.');

            if ($pid === 0) {
                fclose($parentSocket);
                fread($childSocket, 1); // Barrier: start both independent PostgreSQL sessions together.
                DB::purge('payment');
                try {
                    $service->acknowledgeFulfilment(
                        $actor, $intentId, $organisationId, 'paid_event_ticket', $purposeReference,
                        'ticket-order:'.$purposeReference, 'ack:'.$intentId,
                    );
                    fwrite($childSocket, "ok\n");
                    fclose($childSocket);
                    exit(0);
                } catch (\Throwable $exception) {
                    fwrite($childSocket, 'error:'.$exception::class.':'.$exception->getMessage()."\n");
                    fclose($childSocket);
                    exit(1);
                }
            }

            fclose($childSocket);
            $sockets[] = [$pid, $parentSocket];
        }

        foreach ($sockets as [, $socket]) {
            fwrite($socket, 'x');
        }

        $results = [];
        foreach ($sockets as [$pid, $socket]) {
            $results[] = trim((string) fgets($socket));
            fclose($socket);
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status), 'Concurrent acknowledgement worker failed: '.end($results));
        }

        self::assertSame(['ok', 'ok'], $results);
        self::assertSame(1, DB::connection('payment')->table('payment.fulfilment_acknowledgements')
            ->where('payment_intent_id', $this->intentId)->count());
        self::assertSame(PaymentFulfilmentStatus::Fulfilled->value, DB::connection('payment')
            ->table('payment.payment_intents')->where('id', $this->intentId)->value('fulfilment_status'));
    }

    public function test_service_scope_and_organisation_purpose_mismatch_are_denied_without_writes(): void
    {
        try {
            $this->service->acknowledgeFulfilment(
                new ServiceActor('events', 'SERVICE', [], null), $this->intentId, $this->organisationId,
                'paid_event_ticket', $this->purposeReference, 'ticket-order:'.$this->purposeReference, 'ack:'.$this->intentId,
            );
            self::fail('An Events service without the exact scope must be rejected.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::connection('payment')->table('payment.fulfilment_acknowledgements')
                ->where('payment_intent_id', $this->intentId)->count());
        }

        $foreignOrganisationId = (string) Uuid::uuid7();
        try {
            $this->service->acknowledgeFulfilment(
                new ServiceActor(
                    'events',
                    'SERVICE',
                    ['payment.fulfilment.ack:'.$foreignOrganisationId.':paid_event_ticket'],
                    null,
                ),
                $this->intentId,
                $foreignOrganisationId,
                'paid_event_ticket',
                $this->purposeReference, 'ticket-order:'.$this->purposeReference, 'ack:'.$this->intentId,
            );
            self::fail('An organisation mismatch must be rejected.');
        } catch (\DomainException $exception) {
            self::assertSame('Payment purpose scope mismatch.', $exception->getMessage());
        }

        try {
            $otherPurpose = 'another_purpose';
            $this->service->acknowledgeFulfilment(
                new ServiceActor(
                    'events',
                    'SERVICE',
                    ['payment.fulfilment.ack:'.$this->organisationId.':'.$otherPurpose],
                    null,
                ),
                $this->intentId,
                $this->organisationId,
                $otherPurpose,
                $this->purposeReference,
                'ticket-order:'.$this->purposeReference,
                'ack:'.$this->intentId,
            );
            self::fail('A purpose mismatch must be rejected even when the caller has the requested purpose scope.');
        } catch (\DomainException $exception) {
            self::assertSame('Payment purpose scope mismatch.', $exception->getMessage());
        }

        self::assertSame(0, DB::connection('payment')->table('payment.fulfilment_acknowledgements')
            ->where('payment_intent_id', $this->intentId)->count());
    }

    public function test_non_succeeded_or_unposted_intent_cannot_be_fulfilled(): void
    {
        DB::connection('payment')->table('payment.payment_intents')->where('id', $this->intentId)->update([
            'accounting_status' => PaymentAccountingStatus::PendingLedger->value,
        ]);

        try {
            $this->service->acknowledgeFulfilment(
                $this->eventsActor(), $this->intentId, $this->organisationId, 'paid_event_ticket',
                $this->purposeReference, 'ticket-order:'.$this->purposeReference, 'ack:'.$this->intentId,
            );
            self::fail('An intent without posted accounting evidence must not be fulfilled.');
        } catch (\DomainException $exception) {
            self::assertSame('Payment evidence is not eligible for fulfilment acknowledgement.', $exception->getMessage());
        }

        self::assertSame(PaymentFulfilmentStatus::Pending->value, DB::connection('payment')
            ->table('payment.payment_intents')->where('id', $this->intentId)->value('fulfilment_status'));
        self::assertSame(0, DB::connection('payment')->table('payment.fulfilment_acknowledgements')
            ->where('payment_intent_id', $this->intentId)->count());

        DB::connection('payment')->table('payment.payment_intents')->where('id', $this->intentId)->update([
            'status' => PaymentIntentStatus::Failed->value,
            'accounting_status' => PaymentAccountingStatus::LedgerPosted->value,
        ]);

        try {
            $this->service->acknowledgeFulfilment(
                $this->eventsActor(), $this->intentId, $this->organisationId, 'paid_event_ticket',
                $this->purposeReference, 'ticket-order:'.$this->purposeReference, 'ack:'.$this->intentId,
            );
            self::fail('A terminal failed intent must not be fulfilled.');
        } catch (\DomainException $exception) {
            self::assertSame('Payment evidence is not eligible for fulfilment acknowledgement.', $exception->getMessage());
        }

        self::assertSame(0, DB::connection('payment')->table('payment.fulfilment_acknowledgements')
            ->where('payment_intent_id', $this->intentId)->count());
    }

    public function test_acknowledgement_row_rolls_back_if_state_update_fails(): void
    {
        $this->persistCaptureEvidence();
        DB::connection('payment')->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment.p08_test_reject_fulfilment_update() RETURNS trigger AS $$
            BEGIN
                IF NEW.fulfilment_status = 'fulfilled' THEN
                    RAISE EXCEPTION 'p08_test_forced_state_failure';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER p08_test_reject_fulfilment_update
            BEFORE UPDATE ON payment.payment_intents
            FOR EACH ROW EXECUTE FUNCTION payment.p08_test_reject_fulfilment_update();
            SQL);

        try {
            $this->service->acknowledgeFulfilment(
                $this->eventsActor(), $this->intentId, $this->organisationId, 'paid_event_ticket',
                $this->purposeReference, 'ticket-order:'.$this->purposeReference, 'ack:'.$this->intentId,
            );
            self::fail('The test trigger must abort the payment state update.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('p08_test_forced_state_failure', $exception->getMessage());
        } finally {
            DB::connection('payment')->unprepared(
                'DROP TRIGGER IF EXISTS p08_test_reject_fulfilment_update ON payment.payment_intents; '
                .'DROP FUNCTION IF EXISTS payment.p08_test_reject_fulfilment_update()'
            );
        }

        self::assertSame(0, DB::connection('payment')->table('payment.fulfilment_acknowledgements')
            ->where('payment_intent_id', $this->intentId)->count());
        self::assertSame(PaymentFulfilmentStatus::Pending->value, DB::connection('payment')
            ->table('payment.payment_intents')->where('id', $this->intentId)->value('fulfilment_status'));
    }

    private function eventsActor(): ServiceActor
    {
        return new ServiceActor(
            'events',
            'SERVICE',
            [
                'payment.confirmation.read:'.$this->organisationId.':paid_event_ticket',
                'payment.fulfilment.ack:'.$this->organisationId.':paid_event_ticket',
            ],
            null,
        );
    }

    private function persistCaptureEvidence(): void
    {
        DB::connection('payment')->table('payment.payment_attempts')->where('id', $this->attemptId)->update([
            'captured_amount_minor' => 12500,
            'captured_currency_code' => 'BWP',
            'capture_evidence_reference' => $this->captureReference,
            'ledger_journal_id' => $this->journalId,
            'ledger_debit_account_reference' => 'test-debit-account',
            'ledger_credit_account_reference' => 'test-credit-account',
        ]);
    }
}
