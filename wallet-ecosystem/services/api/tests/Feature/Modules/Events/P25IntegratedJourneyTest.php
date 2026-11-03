<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Events;

use App\Jobs\OutboxDispatcherJob;
use App\Modules\Events\Application\EventDraftService;
use App\Modules\Events\Application\EventInventoryHoldService;
use App\Modules\Events\Application\EventOrderService;
use App\Modules\Events\Domain\Contracts\EventDraftAuthorizer;
use App\Modules\Events\Domain\Contracts\EventPricingContract;
use App\Modules\Events\Domain\Contracts\EventsPaymentServiceActorProvider;
use App\Modules\Events\Domain\ValueObjects\EventPricingBreakdown;
use App\Modules\Events\Domain\ValueObjects\EventPricingRequest;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\ServiceActor;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\PaymentConfirmationEvidenceService;
use App\Modules\Payment\Domain\Contracts\PaymentInternalConfirmationContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Infrastructure\EventBus\EventBusDriverInterface;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\TestCase;

/**
 * Integrated P25 development journey on the explicitly named disposable PostgreSQL database.
 * Pricing, provider-account activation, capture account mapping, operator assignment, and the
 * Events service identity are test-only. Payment/Ledger evidence and Events delivery are real.
 */
final class P25IntegratedJourneyTest extends TestCase
{
    /** @var array<string,string> */
    private array $ids = [];

    /** @var array{id:string,is_enabled:bool,is_suspended:bool,environment:string}|null */
    private ?array $providerState = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $expectedDatabase = (string) env('P25_DISPOSABLE_DATABASE', '');
        self::assertNotSame('', $expectedDatabase, 'P25 test refuses to write unless the disposable database is named explicitly.');
        self::assertSame('pgsql', DB::connection('platform')->getDriverName());
        self::assertSame($expectedDatabase, DB::connection('platform')->getDatabaseName(),
            'P25 journey writes are restricted to P25_DISPOSABLE_DATABASE.');
        self::assertSame($expectedDatabase, DB::connection('community_services')->getDatabaseName());

        config([
            'wallet.capture.test_only_enabled' => true,
            'wallet.capture.test_destination_accounts.BWP' => 'p25-test-only-capture-destination:'.Uuid::uuid7(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('platform')->statement('DROP TRIGGER IF EXISTS p25_fail_fulfilment_ack ON payment.fulfilment_acknowledgements');
            DB::connection('platform')->statement('DROP FUNCTION IF EXISTS payment.p25_fail_fulfilment_ack()');
            $this->deleteJourneyRows();
        } finally {
            parent::tearDown();
        }
    }

    public function test_real_wallet_ledger_outbox_issuance_purchaser_admission_and_reporting_journey(): void
    {
        $this->createActorsAndOrganisation();
        $event = $this->createTestPublishedEvent();
        $this->bindTestOnlyPricing();
        $walletId = $this->createFundedWallet();
        $this->enableTestOnlyWalletPaymentAccount($event['currency_code']);

        $hold = app(EventInventoryHoldService::class)->createHold(
            $event['id'], $event['category_id'], $this->ids['purchaser'], 2,
            'p25-journey-hold-'.$this->ids['event'], (string) Uuid::uuid7(),
        );
        $orders = app(EventOrderService::class);
        $quote = $orders->createQuote($event['id'], $this->ids['purchaser'], [$hold['id']], 'p25-journey-quote-'.$this->ids['event']);
        $order = $orders->confirmAndCreateOrder(
            $event['id'], $this->ids['purchaser'], $quote['quote_id'], 'p25-journey-order-'.$this->ids['event'],
        );
        $this->ids['order'] = $order['order_id'];
        self::assertSame(2, array_sum(array_column($order['line_snapshot'], 'quantity')));
        self::assertSame('inventory_hold', DB::connection('community_services')
            ->table('community_services.event_drafts')->where('id', $event['id'])->value('currency_lock_reason'));

        // Actual Events -> shared Payments purpose resolver -> Payment intent path.
        $paymentIntent = $orders->createPaymentIntent($order['order_id'], $this->ids['purchaser'], (string) Uuid::uuid7());
        $this->ids['intent'] = $paymentIntent['payment_intent_id'];
        self::assertSame((int) $order['total_minor'], $paymentIntent['amount_minor']);
        self::assertSame($order['currency_code'], $paymentIntent['currency_code']);
        $storedIntentReference = DB::connection('payment')->table('payment.payment_intents')
            ->where('id', $paymentIntent['payment_intent_id'])->value('reference');
        self::assertSame(
            'P'.strtoupper(substr(str_replace('-', '', $paymentIntent['payment_intent_id']), 0, 31)),
            $storedIntentReference,
        );
        self::assertSame($order['order_id'], DB::connection('payment')->table('payment.payment_intents')
            ->where('id', $paymentIntent['payment_intent_id'])->value('purpose_reference'));

        // The real Wallet adapter creates the hold. Its synchronous success is then applied
        // through Payment's existing outcome service, which performs committed capture and
        // posts/verifies the real Ledger journal before writing payment.confirmed to outbox.
        $correlationId = (string) Uuid::uuid7();
        $attemptResult = app(PaymentOrchestrationService::class)->initiateAttempt([
            'payment_intent_id' => $paymentIntent['payment_intent_id'], 'provider_code' => 'olekard_wallet',
            'payer_actor_id' => $this->ids['purchaser'], 'wallet_id' => $walletId,
            'idempotency_key' => 'p25-journey-attempt-'.$this->ids['event'], 'correlation_id' => $correlationId,
        ]);
        $this->ids['attempt'] = $attemptResult['attempt_id'];
        self::assertSame('succeeded', $attemptResult['status']);
        app(PaymentOrchestrationService::class)->handleOutcome([
            'payment_intent_id' => $paymentIntent['payment_intent_id'], 'payment_attempt_id' => $attemptResult['attempt_id'],
            'outcome' => 'succeeded', 'correlation_id' => $correlationId,
        ]);

        $paymentRow = DB::connection('payment')->table('payment.payment_intents')->where('id', $paymentIntent['payment_intent_id'])->first();
        $attempt = DB::connection('payment')->table('payment.payment_attempts')->where('id', $attemptResult['attempt_id'])->first();
        self::assertSame('succeeded', $paymentRow->status);
        self::assertSame('ledger_posted', $paymentRow->accounting_status);
        self::assertSame((int) $order['total_minor'], (int) $attempt->captured_amount_minor);
        self::assertSame($order['currency_code'], trim((string) $attempt->captured_currency_code));
        self::assertNotNull($attempt->ledger_journal_id);
        self::assertSame(1, DB::connection('ledger')->table('ledger.journals')
            ->where('idempotency_key', 'payment:'.$paymentIntent['payment_intent_id'].':ack')->count());
        self::assertSame(1, DB::connection('wallet')->table('wallet.wallet_capture_records')
            ->where('payment_intent_id', $paymentIntent['payment_intent_id'])->count());
        self::assertSame(1, DB::connection('platform')->table('platform.outbox_events')
            ->where('event_name', 'payment.confirmed')->where('aggregate_id', $paymentIntent['payment_intent_id'])->count());

        // This provider is test-only: failure occurs after Events' durable inbox/ticket transaction
        // and before Payment can persist the fulfilment acknowledgement.
        $this->installAckFailureTrigger($paymentIntent['payment_intent_id']);
        app(OutboxDispatcherJob::class)->handle(app(EventBusDriverInterface::class), app(AuditServiceInterface::class));
        $inbox = DB::connection('community_services')->table('community_services.event_payment_inbox')
            ->where('payment_intent_id', $paymentIntent['payment_intent_id'])->first();
        self::assertNotNull($inbox);
        self::assertSame('processed', $inbox->state);
        self::assertSame('pending', $inbox->ack_state);
        self::assertSame(2, DB::connection('community_services')->table('community_services.event_tickets')
            ->where('order_id', $order['order_id'])->count());
        self::assertSame('failed', DB::connection('platform')->table('platform.outbox_events')
            ->where('aggregate_id', $paymentIntent['payment_intent_id'])->where('event_name', 'payment.confirmed')->value('status'));

        DB::connection('platform')->statement('DROP TRIGGER p25_fail_fulfilment_ack ON payment.fulfilment_acknowledgements');
        DB::connection('platform')->statement('DROP FUNCTION payment.p25_fail_fulfilment_ack()');
        DB::connection('platform')->table('platform.outbox_events')->where('aggregate_id', $paymentIntent['payment_intent_id'])
            ->where('event_name', 'payment.confirmed')->update(['next_attempt_at' => now()->subSecond()]);
        app(OutboxDispatcherJob::class)->handle(app(EventBusDriverInterface::class), app(AuditServiceInterface::class));

        $tickets = DB::connection('community_services')->table('community_services.event_tickets')
            ->where('order_id', $order['order_id'])->orderBy('entitlement_number')->get();
        self::assertCount(2, $tickets);
        self::assertCount(2, $tickets->pluck('credential_hash')->unique());
        self::assertCount(2, $tickets->pluck('credential_encrypted')->unique());
        self::assertSame('complete', DB::connection('community_services')->table('community_services.event_payment_inbox')
            ->where('payment_intent_id', $paymentIntent['payment_intent_id'])->value('ack_state'));
        self::assertSame('fulfilled', DB::connection('payment')->table('payment.payment_intents')
            ->where('id', $paymentIntent['payment_intent_id'])->value('fulfilment_status'));
        self::assertSame(1, DB::connection('payment')->table('payment.fulfilment_acknowledgements')
            ->where('payment_intent_id', $paymentIntent['payment_intent_id'])->count());
        self::assertSame('delivered', DB::connection('platform')->table('platform.outbox_events')
            ->where('aggregate_id', $paymentIntent['payment_intent_id'])->where('event_name', 'payment.confirmed')->value('status'));

        // Re-deliver the exact durable Payment outbox event after completion. Events must
        // acknowledge idempotently without creating tickets, captures, journals, or a second ack.
        $eventRow = OutboxEventModel::query()->where('aggregate_id', $paymentIntent['payment_intent_id'])
            ->where('event_name', 'payment.confirmed')->firstOrFail();
        app(EventBusDriverInterface::class)->dispatch($eventRow);
        self::assertSame(2, DB::connection('community_services')->table('community_services.event_tickets')->where('order_id', $order['order_id'])->count());
        self::assertSame(1, DB::connection('ledger')->table('ledger.journals')->where('idempotency_key', 'payment:'.$paymentIntent['payment_intent_id'].':ack')->count());
        self::assertSame(1, DB::connection('payment')->table('payment.fulfilment_acknowledgements')->where('payment_intent_id', $paymentIntent['payment_intent_id'])->count());

        // Purchaser access is authenticated, actor-scoped and non-cacheable.
        $listed = $this->withHeaders(['X-Test-User-Id' => $this->ids['purchaser'], 'X-Test-Actor-Type' => 'CUSTOMER'])
            ->getJson('/v1/events/tickets');
        $listed->assertOk();
        self::assertEqualsCanonicalizing(
            ['private', 'no-store', 'max-age=0'],
            array_map('trim', explode(',', (string) $listed->headers->get('Cache-Control'))),
        );
        self::assertCount(2, $listed->json('data'));
        $credential = (string) $listed->json('data.0.credential');
        self::assertSame(43, strlen($credential));
        $ownerTicketId = (string) $listed->json('data.0.ticket_id');
        $this->withHeaders(['X-Test-User-Id' => $this->ids['other_customer'], 'X-Test-Actor-Type' => 'CUSTOMER'])
            ->getJson('/v1/events/tickets/'.$ownerTicketId)->assertNotFound();

        $this->createTestGateAndAssignments($event['id']);
        self::assertInstanceOf(PaymentConfirmationEvidenceService::class, app(PaymentInternalConfirmationContract::class));

        $scan = $this->withHeaders(['X-Test-User-Id' => $this->ids['operator'], 'X-Test-Actor-Type' => 'AGENT'])
            ->postJson('/v1/events/'.$event['id'].'/gates/'.$this->ids['gate'].'/validate', ['credential' => $credential]);
        $scan->assertOk()->assertExactJson(['data' => ['status' => 'admitted', 'category' => 'A']]);
        self::assertSame(['status', 'category'], array_keys($scan->json('data')));
        $this->withHeaders(['X-Test-User-Id' => $this->ids['operator'], 'X-Test-Actor-Type' => 'AGENT'])
            ->postJson('/v1/events/'.$event['id'].'/gates/'.$this->ids['gate'].'/validate', ['credential' => $credential])
            ->assertOk()->assertJsonPath('data.status', 'already_admitted');

        $attendance = DB::connection('community_services')->table('community_services.event_attendance')
            ->where('ticket_id', $ownerTicketId)->first();
        self::assertNotNull($attendance);
        self::assertSame($this->ids['operator'], $attendance->operator_actor_id);
        self::assertSame($this->ids['gate'], $attendance->gate_id);
        self::assertSame(1, DB::connection('community_services')->table('community_services.event_attendance_audit')
            ->where('ticket_id', $ownerTicketId)->where('action', 'admitted')->count());
        $auditPayload = json_encode(DB::connection('community_services')->table('community_services.event_attendance_audit')
            ->where('ticket_id', $ownerTicketId)->first(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($credential, $auditPayload);

        $report = $this->withHeaders(['X-Test-User-Id' => $this->ids['operator'], 'X-Test-Actor-Type' => 'AGENT'])
            ->getJson('/v1/events/'.$event['id'].'/attendance')->assertOk()->json('data');
        self::assertSame([['category' => 'A', 'admitted_count' => 1]], $report);
        self::assertStringNotContainsString('ticket_id', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('operator_actor_id', json_encode($report, JSON_THROW_ON_ERROR));

        // The second entitlement remains independently valid and unadmitted.
        $otherTicket = DB::connection('community_services')->table('community_services.event_tickets')
            ->where('order_id', $order['order_id'])->where('id', '!=', $ownerTicketId)->first();
        self::assertNotNull($otherTicket);
        self::assertSame(0, DB::connection('community_services')->table('community_services.event_attendance')
            ->where('ticket_id', $otherTicket->id)->count());

        $confirmedOutboxPayload = DB::connection('platform')->table('platform.outbox_events')
            ->where('aggregate_id', $paymentIntent['payment_intent_id'])->where('event_name', 'payment.confirmed')->value('payload');
        self::assertStringNotContainsString($credential, (string) $confirmedOutboxPayload);
        self::assertSame(1, DB::connection('platform')->table('platform.audit_events')
            ->where('resource_id', $paymentIntent['payment_intent_id'])->where('action', 'payment.ledger.acknowledged')->count());
    }

    private function createActorsAndOrganisation(): void
    {
        $this->ids = [
            'admin' => (string) Uuid::uuid7(), 'purchaser' => (string) Uuid::uuid7(),
            'other_customer' => (string) Uuid::uuid7(), 'operator' => (string) Uuid::uuid7(),
            'organisation' => (string) Uuid::uuid7(), 'event' => (string) Uuid::uuid7(),
        ];
        foreach ([['admin', 'ADMIN'], ['purchaser', 'CUSTOMER'], ['other_customer', 'CUSTOMER'], ['operator', 'AGENT']] as [$key, $type]) {
            DB::connection('identity')->table('identity.users')->insert([
                'id' => $this->ids[$key], 'status' => 'active', 'actor_type' => $type,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::connection('identity')->table('identity.organisations')->insert([
            'id' => $this->ids['organisation'], 'registry_reference' => 'p25-journey-'.$this->ids['organisation'],
            'display_name' => 'P25 integrated test organisation', 'status' => 'active', 'evidence_reference' => 'test-only',
            'requested_by' => $this->ids['admin'], 'reviewed_by' => $this->ids['operator'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('identity')->table('identity.organisation_memberships')->insert([
            'id' => (string) Uuid::uuid7(), 'organisation_id' => $this->ids['organisation'], 'user_id' => $this->ids['admin'],
            'status' => 'active', 'evidence_reference' => 'test-only', 'requested_by' => $this->ids['admin'],
            'reviewed_by' => $this->ids['operator'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $serviceActor = new ServiceActor('events', 'SERVICE', [
            'payment.confirmation.read:'.$this->ids['organisation'].':paid_event_ticket',
            'payment.fulfilment.ack:'.$this->ids['organisation'].':paid_event_ticket',
        ], null);
        $this->app->instance(EventsPaymentServiceActorProvider::class, new class($serviceActor) implements EventsPaymentServiceActorProvider
        {
            public function __construct(private readonly ServiceActor $actor) {}

            public function actorFor(string $organisationId): ServiceActor
            {
                return $this->actor;
            }
        });
    }

    /** @return array{id:string,category_id:string,currency_code:string} */
    private function createTestPublishedEvent(): array
    {
        $this->app->instance(EventDraftAuthorizer::class, new class implements EventDraftAuthorizer
        {
            public function assertCanCreate(string $actorId, string $organisationId): void {}

            public function assertCanRead(string $actorId, string $organisationId, string $eventId): void {}

            public function assertCanUpdate(string $actorId, string $organisationId, string $eventId): void {}
        });
        $currency = DB::connection('platform')->table('multi_currency.currencies')->where('code', 'BWP')->where('is_active', true)
            ->first(['code', 'decimal_places', 'updated_at']);
        self::assertNotNull($currency, 'Disposable DB must contain an active BWP definition from project migrations.');
        $input = [
            'title' => 'P25 integrated journey test', 'event_format' => 'physical', 'venue_country_code' => 'BW',
            'organiser_name' => 'Test organiser', 'sponsors' => [], 'capacity' => 3, 'timezone' => 'Africa/Gaborone',
            'max_tickets_per_order' => 2, 'starts_at' => '2027-03-01T09:00:00+02:00', 'ends_at' => '2027-03-01T12:00:00+02:00',
            'sales_start_at' => '2026-10-01T09:00:00+02:00', 'sales_end_at' => '2027-02-28T09:00:00+02:00',
            'hold_duration_seconds' => 3600, 'cancellation_policy' => 'test-only', 'transfer_policy' => 'test-only',
            'entry_policy' => 'test-only', 'categories' => [['name' => 'A', 'capacity' => 3, 'price_minor' => 1000]],
        ];
        $draft = app(EventDraftService::class)->create($this->ids['organisation'], $this->ids['admin'], $input);
        $this->ids['event'] = $draft['id'];
        $categoryId = (string) $draft['categories'][0]->id;
        $assignmentId = (string) Uuid::uuid7();
        DB::connection('community_services')->table('community_services.event_currency_assignments')->insert([
            'id' => $assignmentId, 'event_id' => $draft['id'], 'organisation_id' => $this->ids['organisation'],
            'currency_code' => $currency->code, 'precision_snapshot' => $currency->decimal_places,
            'currency_definition_updated_at' => $currency->updated_at, 'assigned_by_actor_id' => $this->ids['admin'],
            'approval_reference' => 'test-only-p25-fixture', 'policy_version' => 'test-only',
            'assigned_at' => now(), 'superseded_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('community_services')->table('community_services.event_drafts')->where('id', $draft['id'])->update([
            'workflow_state' => 'published', 'sales_state' => 'active', 'published_version' => 1,
            'currency_code' => $currency->code, 'currency_precision_snapshot' => $currency->decimal_places,
            'currency_definition_updated_at' => $currency->updated_at, 'currency_assignment_id' => $assignmentId,
        ]);

        return ['id' => $draft['id'], 'category_id' => $categoryId, 'currency_code' => $currency->code];
    }

    private function bindTestOnlyPricing(): void
    {
        $this->app->instance(EventPricingContract::class, new class implements EventPricingContract
        {
            public function quote(EventPricingRequest $request): EventPricingBreakdown
            {
                $subtotal = $request->subtotalMinor();
                $fee = $subtotal > 0 ? 30 : 0;
                $tax = $subtotal > 0 ? 200 : 0;

                return new EventPricingBreakdown($subtotal, $fee, $tax, $subtotal + $fee + $tax,
                    $request->currencyCode, $request->currencyPrecision, 'p25-test-only-seller',
                    'p25-test-only-fee-policy', 'p25-test-only-tax-policy',
                    [['code' => 'test-fee', 'amount_minor' => $fee, 'policy_version' => 'p25-test-only-fee-policy']],
                    [['code' => 'test-tax', 'amount_minor' => $tax, 'policy_version' => 'p25-test-only-tax-policy']],
                    now()->addMinutes(20)->toImmutable(), 'p25-test-only-pricing-evidence', false, false, true);
            }
        });
    }

    private function createFundedWallet(): string
    {
        $walletId = (string) Uuid::uuid7();
        $pocketId = (string) Uuid::uuid7();
        $reference = 'p25-journey-pocket:'.$pocketId;
        $this->ids['wallet'] = $walletId;
        DB::connection('wallet')->table('wallet.wallets')->insert([
            'id' => $walletId, 'wallet_reference' => 'P25-'.strtoupper(substr(str_replace('-', '', $walletId), 0, 18)),
            'owner_id' => $this->ids['purchaser'], 'status' => 'active', 'version' => 1,
            'created_by' => $this->ids['purchaser'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('wallet')->table('wallet.currency_pockets')->insert([
            'id' => $pocketId, 'wallet_id' => $walletId, 'currency_code' => 'BWP',
            'ledger_account_reference' => $reference, 'status' => 'active', 'created_by' => $this->ids['purchaser'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(LedgerModuleContract::class)->postEntry([
            'idempotency_key' => 'p25-test-only-wallet-fund:'.$walletId, 'entry_type' => 'credit',
            'currency_code' => 'BWP', 'transaction_reference' => $walletId, 'correlation_id' => (string) Uuid::uuid7(),
            'actor_id' => $this->ids['purchaser'], 'lines' => [
                ['ledger_account_reference' => 'p25-test-only-funding:'.$walletId, 'direction' => 'debit', 'amount' => 5000],
                ['ledger_account_reference' => $reference, 'direction' => 'credit', 'amount' => 5000],
            ],
        ]);
        $this->ids['wallet'] = $walletId;

        return $walletId;
    }

    private function enableTestOnlyWalletPaymentAccount(string $currency): void
    {
        $payment = DB::connection('payment');
        $provider = $payment->table('payment.payment_providers')->where('code', 'olekard_wallet')->first();
        self::assertNotNull($provider, 'Project Payment migrations must seed the supported Wallet provider.');
        $this->providerState = [
            'id' => (string) $provider->id, 'is_enabled' => (bool) $provider->is_enabled,
            'is_suspended' => (bool) $provider->is_suspended, 'environment' => (string) $provider->environment,
        ];
        $payment->table('payment.payment_providers')->where('id', $provider->id)->update([
            'is_enabled' => true, 'is_suspended' => false, 'environment' => 'sandbox', 'updated_at' => now(),
        ]);
        $accountId = (string) Uuid::uuid7();
        $this->ids['provider_account'] = $accountId;
        $payment->table('payment.payment_provider_accounts')->insert([
            'id' => $accountId, 'provider_id' => $provider->id, 'merchant_account_reference' => 'p25-test-only-'.$accountId,
            'supported_currencies' => json_encode([$currency], JSON_THROW_ON_ERROR),
            'supported_payment_methods' => json_encode(['wallet'], JSON_THROW_ON_ERROR),
            'min_amount_minor' => 1, 'max_amount_minor' => 1000000,
            'enabled_organisations' => json_encode([$this->ids['organisation']], JSON_THROW_ON_ERROR),
            // The current Payment attempt resolver accepts only unrestricted module accounts;
            // organisation and purpose are still constrained in this development fixture.
            'enabled_modules' => json_encode([], JSON_THROW_ON_ERROR),
            'country_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
            'capability_flags' => json_encode([], JSON_THROW_ON_ERROR), 'fee_rule_ids' => json_encode([], JSON_THROW_ON_ERROR),
            'daily_limit_minor' => null, 'is_active' => true, 'config_version' => 1,
            'enabled_purpose_types' => json_encode(['paid_event_ticket'], JSON_THROW_ON_ERROR),
            'account_lifecycle_status' => 'active', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ids['provider_account'] = $accountId;
    }

    private function installAckFailureTrigger(string $intentId): void
    {
        DB::connection('platform')->unprepared("CREATE FUNCTION payment.p25_fail_fulfilment_ack() RETURNS trigger LANGUAGE plpgsql AS \$body\$ BEGIN IF NEW.payment_intent_id = '{$intentId}'::uuid THEN RAISE EXCEPTION 'P25 test fault before fulfilment acknowledgement'; END IF; RETURN NEW; END; \$body\$");
        DB::connection('platform')->statement('CREATE TRIGGER p25_fail_fulfilment_ack BEFORE INSERT ON payment.fulfilment_acknowledgements FOR EACH ROW EXECUTE FUNCTION payment.p25_fail_fulfilment_ack()');
    }

    private function createTestGateAndAssignments(string $eventId): void
    {
        $db = DB::connection('community_services');
        $roleId = (string) Uuid::uuid7();
        $this->ids['operator_role'] = $roleId;
        DB::connection('identity')->table('identity.roles')->insert([
            'id' => $roleId, 'slug' => 'p25gate-'.substr(str_replace('-', '', $roleId), -12), 'display_name' => 'P25 test gate operator',
            'is_system_role' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['events.gate.validate', 'events.attendance.read'] as $permissionSlug) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(), 'slug' => $permissionSlug, 'display_name' => $permissionSlug,
                'module' => 'events', 'is_system_permission' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $permissionId = DB::connection('identity')->table('identity.permissions')->where('slug', $permissionSlug)->value('id');
            DB::connection('identity')->table('identity.role_permissions')->insert([
                'id' => (string) Uuid::uuid7(), 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(),
            ]);
        }
        DB::connection('identity')->table('identity.users')->where('id', $this->ids['operator'])->update(['default_role_id' => $roleId]);
        self::assertTrue(app(IdentityModuleContract::class)->hasPermission($this->ids['operator'], 'events.gate.validate'));
        $this->ids['gate'] = (string) Uuid::uuid7();
        $db->table('community_services.event_gates')->insert([
            'id' => $this->ids['gate'], 'organisation_id' => $this->ids['organisation'], 'event_id' => $eventId,
            'name' => 'Test gate', 'status' => 'active', 'created_by_actor_id' => $this->ids['admin'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$this->ids['operator'], $this->ids['gate'], 'gate', 'events.gate.validate'],
            [$this->ids['operator'], $eventId, 'event', 'events.attendance.read']] as [$actor, $scope, $scopeType, $permission]) {
            $db->table('community_services.event_authority_assignments')->insert([
                'id' => (string) Uuid::uuid7(), 'scope_type' => $scopeType, 'scope_id' => $scope,
                'organisation_id' => $this->ids['organisation'], 'event_id' => $eventId,
                'gate_id' => $scopeType === 'gate' ? $scope : null, 'actor_id' => $actor,
                'permission_slug' => $permission, 'granted_by_actor_id' => $this->ids['admin'],
                'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function deleteJourneyRows(): void
    {
        if (($this->ids['event'] ?? '') === '') {
            return;
        }
        $community = DB::connection('community_services');
        $orderId = $this->ids['order'] ?? null;
        if ($orderId !== null) {
            $ticketIds = $community->table('community_services.event_tickets')->where('order_id', $orderId)->pluck('id')->all();
            if ($ticketIds !== []) {
                $community->table('community_services.event_attendance_audit')->whereIn('ticket_id', $ticketIds)->delete();
                $community->table('community_services.event_attendance')->whereIn('ticket_id', $ticketIds)->delete();
                $community->table('community_services.event_ticket_security_audit')->whereIn('ticket_id', $ticketIds)->delete();
            }
            $community->table('community_services.event_tickets')->where('order_id', $orderId)->delete();
            $community->table('community_services.event_order_lines')->where('order_id', $orderId)->delete();
            $community->table('community_services.event_orders')->where('id', $orderId)->delete();
        }
        $intentId = $this->ids['intent'] ?? null;
        if ($intentId !== null) {
            $community->table('community_services.event_payment_inbox_conflicts')->where('payment_intent_id', $intentId)->delete();
            $community->table('community_services.event_payment_inbox')->where('payment_intent_id', $intentId)->delete();
        }
        $community->table('community_services.event_order_quotes')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_inventory_holds')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_attendance_audit')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_attendance')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_draft_audit')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_draft_version_contributors')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_authority_assignments')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_gates')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_currency_assignments')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_currency_review_issues')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_draft_categories')->where('event_id', $this->ids['event'])->delete();
        $community->table('community_services.event_drafts')->where('id', $this->ids['event'])->delete();

        if ($intentId !== null) {
            DB::connection('payment')->table('payment.fulfilment_acknowledgements')->where('payment_intent_id', $intentId)->delete();
            DB::connection('payment')->table('payment.payment_attempts')->where('payment_intent_id', $intentId)->delete();
            $journal = DB::connection('ledger')->table('ledger.journals')->where('idempotency_key', 'payment:'.$intentId.':ack')->first();
            if ($journal !== null) {
                DB::connection('ledger')->table('ledger.journal_lines')->where('journal_id', $journal->id)->delete();
                DB::connection('ledger')->table('ledger.journals')->where('id', $journal->id)->delete();
            }
            DB::connection('payment')->table('payment.payment_intents')->where('id', $intentId)->delete();
            DB::connection('wallet')->table('wallet.wallet_capture_records')->where('payment_intent_id', $intentId)->delete();
            DB::connection('platform')->table('platform.outbox_events')->where('aggregate_id', $intentId)->delete();
        }
        if (($this->ids['attempt'] ?? '') !== '') {
            DB::connection('wallet')->table('wallet.wallet_holds')->where('transaction_reference', $this->ids['attempt'])->delete();
        }
        if (($this->ids['provider_account'] ?? '') !== '') {
            DB::connection('payment')->table('payment.payment_provider_accounts')->where('id', $this->ids['provider_account'])->delete();
        }
        if (($this->ids['wallet'] ?? '') !== '') {
            DB::connection('wallet')->table('wallet.currency_pockets')->where('wallet_id', $this->ids['wallet'])->delete();
            DB::connection('wallet')->table('wallet.wallets')->where('id', $this->ids['wallet'])->delete();
            $fundingJournal = DB::connection('ledger')->table('ledger.journals')->where('idempotency_key', 'p25-test-only-wallet-fund:'.$this->ids['wallet'])->first();
            if ($fundingJournal !== null) {
                DB::connection('ledger')->table('ledger.journal_lines')->where('journal_id', $fundingJournal->id)->delete();
                DB::connection('ledger')->table('ledger.journals')->where('id', $fundingJournal->id)->delete();
            }
        }
        if (($this->ids['organisation'] ?? '') !== '') {
            DB::connection('identity')->table('identity.organisation_memberships')->where('organisation_id', $this->ids['organisation'])->delete();
            DB::connection('identity')->table('identity.organisations')->where('id', $this->ids['organisation'])->delete();
        }
        foreach (['admin', 'purchaser', 'other_customer', 'operator'] as $actor) {
            if (($this->ids[$actor] ?? '') !== '') {
                DB::connection('identity')->table('identity.users')->where('id', $this->ids[$actor])->delete();
            }
        }
        if (($this->ids['operator_role'] ?? '') !== '') {
            DB::connection('identity')->table('identity.role_permissions')->where('role_id', $this->ids['operator_role'])->delete();
            DB::connection('identity')->table('identity.roles')->where('id', $this->ids['operator_role'])->delete();
        }
        if ($this->providerState !== null) {
            DB::connection('payment')->table('payment.payment_providers')->where('id', $this->providerState['id'])->update([
                'is_enabled' => $this->providerState['is_enabled'],
                'is_suspended' => $this->providerState['is_suspended'],
                'environment' => $this->providerState['environment'], 'updated_at' => now(),
            ]);
        }
    }
}
