<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Events;

use App\Modules\Events\Application\AdmissionCredentialVault;
use App\Modules\Events\Application\EventAdmissionService;
use App\Modules\Events\Application\EventAssignmentManagementService;
use App\Modules\Events\Application\EventCurrencyAssignmentService;
use App\Modules\Events\Application\EventDraftService;
use App\Modules\Events\Application\EventInventoryHoldService;
use App\Modules\Events\Application\EventOrderService;
use App\Modules\Events\Application\EventPaymentConfirmationConsumer;
use App\Modules\Events\Application\EventPaymentReadinessQueryFactory;
use App\Modules\Events\Application\EventWorkflowService;
use App\Modules\Events\Application\FreeEventRegistrationService;
use App\Modules\Events\Application\ScopedEventAuthorization;
use App\Modules\Events\Domain\Contracts\BootstrapApprovalEvidenceVerifier;
use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\Contracts\EventDraftAuthorizer;
use App\Modules\Events\Domain\Contracts\EventPricingContract;
use App\Modules\Events\Domain\Contracts\EventPublicationReadiness;
use App\Modules\Events\Domain\Contracts\EventsPaymentServiceActorProvider;
use App\Modules\Events\Domain\Contracts\EventWorkflowAuthorizer;
use App\Modules\Events\Domain\Contracts\InvitationRecipientVerifier;
use App\Modules\Events\Domain\Exceptions\EventPricingUnavailable;
use App\Modules\Events\Domain\Permissions\EventPermissions;
use App\Modules\Events\Domain\ValueObjects\ComplimentaryAllocationApprovalEvidence;
use App\Modules\Events\Domain\ValueObjects\EventPricingBreakdown;
use App\Modules\Events\Domain\ValueObjects\EventPricingRequest;
use App\Modules\Events\Infrastructure\DenyComplimentaryAllocationApproval;
use App\Modules\Events\Infrastructure\DenyEventPublicationReadiness;
use App\Modules\Events\Infrastructure\PaidEventTicketPurposeResolver;
use App\Modules\Events\Infrastructure\ScopedEventDraftAuthorizer;
use App\Modules\Events\Infrastructure\ScopedEventWorkflowAuthorizer;
use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\ServiceActor;
use App\Modules\MultiCurrency\Domain\Contracts\CurrencyDefinitionGuard;
use App\Modules\Payment\Domain\Contracts\PaymentInternalConfirmationContract;
use App\Modules\Payment\Domain\Contracts\PaymentModuleContract;
use App\Modules\Payment\Domain\Contracts\PaymentRefundAdmissionEligibilityContract;
use App\Modules\Payment\Domain\ValueObjects\PaymentConfirmationEvidence;
use App\Modules\Payment\Domain\ValueObjects\PaymentProviderOption;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Modules\Integration\IntegrationTestCase;
use Tests\Support\FakeIdentityAuthMiddleware;

final class EventDraftFoundationTest extends IntegrationTestCase
{
    use P25AdmissionTestHelpers;

    protected $connectionsToTransact = [null, 'identity', 'platform', 'community_services'];

    private string $actor;

    private string $organisation;

    private string $membership;

    public function test_production_pricing_unavailable_creates_no_quote_order_or_payment_intent(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $hold = app(EventInventoryHoldService::class)->createHold($event['id'], (string) $event['categories'][0]->id,
            $this->actor, 1, 'pricing-deny-hold', (string) Uuid::uuid7());
        $this->expectException(EventPricingUnavailable::class);
        try {
            app(EventOrderService::class)->createQuote($event['id'], $this->actor, [$hold['id']], 'pricing-deny-quote');
        } finally {
            $this->assertSame(0, DB::connection('community_services')->table('community_services.event_order_quotes')
                ->where('event_id', $event['id'])->count());
            $this->assertSame(0, DB::connection('community_services')->table('community_services.event_orders')
                ->where('event_id', $event['id'])->count());
        }
    }

    public function test_order_locks_currency_and_idempotently_issues_distinct_per_ticket_credentials(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $this->bindTestPricing();
        $payments = \Mockery::mock(PaymentModuleContract::class);
        $intentId = (string) Uuid::uuid7();
        $payments->shouldReceive('getAvailableOptions')->once()->andReturn([
            new PaymentProviderOption('test_only_wallet', 'Test only wallet', true, true, 1),
        ]);
        $payments->shouldReceive('createPaymentIntent')->once()->andReturn([
            'payment_intent_id' => $intentId, 'payment_intent_reference' => $intentId, 'status' => 'pending',
            'amount_minor' => 3230, 'currency_code' => $event['currency_code'], 'expires_at' => now()->addMinutes(5)->toImmutable(),
            'correlation_id' => (string) Uuid::uuid7(),
        ]);
        $this->app->instance(PaymentModuleContract::class, $payments);
        $categoryId = (string) $event['categories'][0]->id;
        $hold = app(EventInventoryHoldService::class)->createHold($event['id'], $categoryId, $this->actor, 3,
            'order-hold-'.Uuid::uuid7(), (string) Uuid::uuid7());
        $quotes = app(EventOrderService::class);
        $quote = $quotes->createQuote($event['id'], $this->actor, [$hold['id']], 'order-quote-'.Uuid::uuid7());
        $orderKey = 'order-create-'.Uuid::uuid7();
        $order = $quotes->confirmAndCreateOrder($event['id'], $this->actor, $quote['quote_id'], $orderKey);
        $replay = $quotes->confirmAndCreateOrder($event['id'], $this->actor, $quote['quote_id'], $orderKey);
        $this->assertSame($order['order_id'], $replay['order_id']);
        $stored = DB::connection('community_services')->table('community_services.event_orders')->where('id', $order['order_id'])->first();
        $this->assertSame(3230, (int) $stored->total_minor);
        $obligation = app(PaidEventTicketPurposeResolver::class)->resolve($order['order_id'], $this->actor, (string) Uuid::uuid7());
        $this->assertSame(3230, $obligation->amountMinor);
        $this->assertSame($this->organisation, $obligation->organisationId);
        $this->assertTrue($obligation->priceIncludesFeesAndTaxes);
        $this->assertSame('inventory_hold', DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $event['id'])->value('currency_lock_reason'));
        $quotes->createPaymentIntent($order['order_id'], $this->actor, (string) Uuid::uuid7());

        $evidence = new PaymentConfirmationEvidence($intentId, (string) Uuid::uuid7(), $this->organisation, $this->actor,
            'paid_event_ticket', $order['order_id'], 3230, $event['currency_code'], (string) Uuid::uuid7(), 'ledger_posted');
        $paymentEvidence = \Mockery::mock(PaymentInternalConfirmationContract::class);
        $paymentEvidence->shouldReceive('confirmationEvidence')->twice()->andReturn($evidence);
        $paymentEvidence->shouldReceive('acknowledgeFulfilment')->once();
        $this->app->instance(PaymentInternalConfirmationContract::class, $paymentEvidence);
        $serviceActor = new ServiceActor('events', 'SERVICE', [
            'payment.confirmation.read:'.$this->organisation.':paid_event_ticket',
            'payment.fulfilment.ack:'.$this->organisation.':paid_event_ticket',
        ], null);
        $actors = \Mockery::mock(EventsPaymentServiceActorProvider::class);
        $actors->shouldReceive('actorFor')->twice()->andReturn($serviceActor);
        $this->app->instance(EventsPaymentServiceActorProvider::class, $actors);
        $consumer = app(EventPaymentConfirmationConsumer::class);
        $payload = ['eventId' => (string) Uuid::uuid7(), 'payment_intent_id' => $intentId,
            'payment_attempt_id' => $evidence->paymentAttemptId, 'purpose_type' => 'paid_event_ticket',
            'purpose_reference' => $order['order_id'], 'organisation_id' => $this->organisation, 'payer_actor_id' => $this->actor,
            'amount_minor' => 3230, 'currency_code' => $event['currency_code']];
        $consumer->handle($payload);
        $consumer->handle($payload);

        $tickets = DB::connection('community_services')->table('community_services.event_tickets')->where('order_id', $order['order_id'])->get();
        $this->assertCount(3, $tickets);
        $this->assertCount(3, $tickets->pluck('credential_hash')->unique());
        $this->assertSame('issued', DB::connection('community_services')->table('community_services.event_orders')->where('id', $order['order_id'])->value('fulfilment_state'));
        $this->assertSame('complete', DB::connection('community_services')->table('community_services.event_payment_inbox')->where('payment_intent_id', $intentId)->value('ack_state'));
        $display = $quotes->orderForPurchaser($order['order_id'], $this->actor);
        $this->assertCount(3, $display['tickets']);
        $this->assertCount(3, array_unique(array_column($display['tickets'], 'credential')));
    }

    public function test_durable_order_pricing_snapshot_is_unchanged_after_test_policy_configuration_changes(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $this->bindTestPricing('test-fees-v1', 30, 'test-tax-v1', 200);
        $hold = app(EventInventoryHoldService::class)->createHold($event['id'], (string) $event['categories'][0]->id,
            $this->actor, 1, 'historical-pricing-hold', (string) Uuid::uuid7());
        $orders = app(EventOrderService::class);
        $quote = $orders->createQuote($event['id'], $this->actor, [$hold['id']], 'historical-pricing-quote');
        $quoteBefore = DB::connection('community_services')->table('community_services.event_order_quotes')
            ->where('id', $quote['quote_id'])->value('pricing_snapshot');
        $order = $orders->confirmAndCreateOrder($event['id'], $this->actor, $quote['quote_id'], 'historical-pricing-order');
        $before = DB::connection('community_services')->table('community_services.event_orders')
            ->where('id', $order['order_id'])->value('pricing_snapshot');

        // Synthetic test configuration change only; this is not Finance approval or an operational schedule update.
        $this->bindTestPricing('test-fees-v2', 47, 'test-tax-v2', 219);
        $this->app->forgetInstance(EventOrderService::class);
        $ordersAfterPolicyChange = app(EventOrderService::class);
        $retriedQuote = $ordersAfterPolicyChange->createQuote($event['id'], $this->actor, [$hold['id']], 'historical-pricing-quote');
        $quoteAfter = DB::connection('community_services')->table('community_services.event_order_quotes')
            ->where('id', $quote['quote_id'])->value('pricing_snapshot');
        $afterRead = $ordersAfterPolicyChange->orderForPurchaser($order['order_id'], $this->actor);
        $after = DB::connection('community_services')->table('community_services.event_orders')
            ->where('id', $order['order_id'])->value('pricing_snapshot');

        $this->assertSame($before, $after);
        $this->assertSame($quote['quote_id'], $retriedQuote['quote_id']);
        $this->assertSame($quoteBefore, $quoteAfter);
        $storedSnapshot = json_decode((string) $after, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('test-fees-v1', $storedSnapshot['fee_policy_version']);
        $this->assertSame('test-tax-v1', $storedSnapshot['tax_policy_version']);
        $this->assertSame(1230, $afterRead['total_minor']);
    }

    public function test_non_test_pricing_with_fee_schedule_for_another_event_creates_no_quote(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $this->bindSyntheticApprovedPricing('event', (string) Uuid::uuid7());
        $hold = app(EventInventoryHoldService::class)->createHold($event['id'], (string) $event['categories'][0]->id,
            $this->actor, 1, 'mismatched-fee-scope-hold', (string) Uuid::uuid7());

        $this->assertHttp(409, fn () => app(EventOrderService::class)->createQuote(
            $event['id'], $this->actor, [$hold['id']], 'mismatched-fee-scope-quote'));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_order_quotes')
            ->where('event_id', $event['id'])->count());
    }

    public function test_non_test_pricing_outside_fee_or_tax_effective_window_creates_no_quote(): void
    {
        foreach ([
            ['fee_from' => now()->addDay()->toAtomString(), 'tax_from' => '2026-01-01T00:00:00+00:00'],
            ['fee_from' => '2026-01-01T00:00:00+00:00', 'tax_from' => now()->addDay()->toAtomString()],
            ['fee_from' => '2026-01-01T00:00:00+00:00', 'tax_from' => '2026-01-01T00:00:00+00:00',
                'fee_until' => now()->subDay()->toAtomString()],
            ['fee_from' => '2026-01-01T00:00:00+00:00', 'tax_from' => '2026-01-01T00:00:00+00:00',
                'tax_until' => now()->subDay()->toAtomString()],
        ] as $index => $effectiveDates) {
            $event = $this->publishedInventoryEvent(3600);
            $this->bindSyntheticApprovedPricing('organisation', $this->organisation,
                feeEffectiveFrom: $effectiveDates['fee_from'], taxEffectiveFrom: $effectiveDates['tax_from'],
                feeEffectiveUntil: $effectiveDates['fee_until'] ?? null, taxEffectiveUntil: $effectiveDates['tax_until'] ?? null);
            $hold = app(EventInventoryHoldService::class)->createHold($event['id'], (string) $event['categories'][0]->id,
                $this->actor, 1, 'future-policy-hold-'.$index.'-'.Uuid::uuid7(), (string) Uuid::uuid7());

            $this->assertHttp(409, fn () => app(EventOrderService::class)->createQuote(
                $event['id'], $this->actor, [$hold['id']], 'future-policy-quote-'.$index.'-'.Uuid::uuid7()));
            $this->assertSame(0, DB::connection('community_services')->table('community_services.event_order_quotes')
                ->where('event_id', $event['id'])->count());
        }
    }

    public function test_verified_capture_after_hold_expiry_is_recorded_for_reconciliation_without_ticket(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $this->bindTestPricing();
        $hold = app(EventInventoryHoldService::class)->createHold($event['id'], (string) $event['categories'][0]->id,
            $this->actor, 1, 'late-order-hold', (string) Uuid::uuid7());
        $orders = app(EventOrderService::class);
        $quote = $orders->createQuote($event['id'], $this->actor, [$hold['id']], 'late-order-quote');
        $order = $orders->confirmAndCreateOrder($event['id'], $this->actor, $quote['quote_id'], 'late-order-create');
        $intentId = (string) Uuid::uuid7();
        $evidence = new PaymentConfirmationEvidence($intentId, (string) Uuid::uuid7(), $this->organisation, $this->actor,
            'paid_event_ticket', $order['order_id'], 1230, $event['currency_code'], (string) Uuid::uuid7(), 'ledger_posted');
        $paymentEvidence = \Mockery::mock(PaymentInternalConfirmationContract::class);
        $paymentEvidence->shouldReceive('confirmationEvidence')->once()->andReturn($evidence);
        $paymentEvidence->shouldNotReceive('acknowledgeFulfilment');
        $this->app->instance(PaymentInternalConfirmationContract::class, $paymentEvidence);
        $serviceActor = new ServiceActor('events', 'SERVICE', [
            'payment.confirmation.read:'.$this->organisation.':paid_event_ticket',
            'payment.fulfilment.ack:'.$this->organisation.':paid_event_ticket',
        ], null);
        $actors = \Mockery::mock(EventsPaymentServiceActorProvider::class);
        $actors->shouldReceive('actorFor')->once()->andReturn($serviceActor);
        $this->app->instance(EventsPaymentServiceActorProvider::class, $actors);
        DB::connection('community_services')->table('community_services.event_inventory_holds')->where('id', $hold['id'])
            ->update(['expires_at' => now()->subSecond(), 'status' => 'expired']);
        app(EventPaymentConfirmationConsumer::class)->handle([
            'eventId' => (string) Uuid::uuid7(), 'payment_intent_id' => $intentId,
            'purpose_type' => 'paid_event_ticket', 'purpose_reference' => $order['order_id'],
            'organisation_id' => $this->organisation, 'payer_actor_id' => $this->actor,
            'amount_minor' => 1230, 'currency_code' => $event['currency_code'],
        ]);
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_tickets')->where('order_id', $order['order_id'])->count());
        $this->assertSame('blocked_reconciliation', DB::connection('community_services')->table('community_services.event_payment_inbox')->where('payment_intent_id', $intentId)->value('state'));
        $this->assertSame('blocked_reconciliation', DB::connection('community_services')->table('community_services.event_orders')->where('id', $order['order_id'])->value('order_state'));
    }

    public function test_ticket_transaction_rolls_back_when_a_ticket_record_cannot_be_written(): void
    {
        $prepared = $this->prepareOrder(2, 'rollback');
        $intentId = (string) Uuid::uuid7();
        $this->linkPaymentIntent($prepared['order']['order_id'], $intentId, $prepared['order']['total_minor'], $prepared['order']['currency_code']);
        $this->bindConfirmation($prepared, $intentId, static function (): void {}, readCalls: 1, ackCalls: 0);
        $db = DB::connection('community_services');
        $this->assertSame('pgsql', $db->getDriverName(), 'Fault-boundary rollback test requires PostgreSQL.');
        $db->statement("CREATE OR REPLACE FUNCTION community_services.p25_test_reject_ticket() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'injected ticket persistence failure'; END $$");
        $db->statement('CREATE TRIGGER p25_test_reject_ticket BEFORE INSERT ON community_services.event_tickets FOR EACH ROW EXECUTE FUNCTION community_services.p25_test_reject_ticket()');
        try {
            try {
                app(EventPaymentConfirmationConsumer::class)->handle($this->confirmationPayload($prepared, $intentId));
                $this->fail('Injected write failure must abort the Events issuance transaction.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('injected ticket persistence failure', $exception->getMessage());
            }
        } finally {
            $db->statement('DROP TRIGGER IF EXISTS p25_test_reject_ticket ON community_services.event_tickets');
            $db->statement('DROP FUNCTION IF EXISTS community_services.p25_test_reject_ticket()');
        }
        $this->assertSame(0, $db->table('community_services.event_tickets')->where('order_id', $prepared['order']['order_id'])->count());
        $this->assertSame(0, $db->table('community_services.event_payment_inbox')->where('payment_intent_id', $intentId)->count());
        $this->assertSame('awaiting_payment', $db->table('community_services.event_orders')->where('id', $prepared['order']['order_id'])->value('order_state'));
    }

    public function test_ticket_commit_before_ack_is_replayed_without_duplicate_entitlement(): void
    {
        $prepared = $this->prepareOrder(1, 'ack-retry');
        $intentId = (string) Uuid::uuid7();
        $this->linkPaymentIntent($prepared['order']['order_id'], $intentId, $prepared['order']['total_minor'], $prepared['order']['currency_code']);
        $calls = 0;
        $this->bindConfirmation($prepared, $intentId, static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('simulated process interruption after Events commit');
            }
        });
        $consumer = app(EventPaymentConfirmationConsumer::class);
        $payload = $this->confirmationPayload($prepared, $intentId);
        try {
            $consumer->handle($payload);
            $this->fail('Acknowledgement interruption should surface for outbox retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated process interruption after Events commit', $exception->getMessage());
        }
        $db = DB::connection('community_services');
        $this->assertSame(1, $db->table('community_services.event_tickets')->where('order_id', $prepared['order']['order_id'])->count());
        $this->assertSame('pending', $db->table('community_services.event_payment_inbox')->where('payment_intent_id', $intentId)->value('ack_state'));

        $consumer->handle($payload);
        $this->assertSame(1, $db->table('community_services.event_tickets')->where('order_id', $prepared['order']['order_id'])->count());
        $this->assertSame('complete', $db->table('community_services.event_payment_inbox')->where('payment_intent_id', $intentId)->value('ack_state'));
        $this->assertSame(2, $calls);
    }

    public function test_quote_requires_new_confirmation_after_component_change_and_wrong_purchaser_is_denied(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $this->bindTestPricing();
        $hold = app(EventInventoryHoldService::class)->createHold($event['id'], (string) $event['categories'][0]->id,
            $this->actor, 1, 'changed-price-hold', (string) Uuid::uuid7());
        $orders = app(EventOrderService::class);
        $this->assertHttp(404, fn () => $orders->createQuote($event['id'], (string) Uuid::uuid7(), [$hold['id']], 'wrong-buyer'));
        $quote = $orders->createQuote($event['id'], $this->actor, [$hold['id']], 'changed-price-quote');
        $this->app->instance(EventPricingContract::class, new class implements EventPricingContract
        {
            public function quote(EventPricingRequest $request): EventPricingBreakdown
            {
                $subtotal = $request->subtotalMinor();

                return new EventPricingBreakdown($subtotal, 31, 200, $subtotal + 231, $request->currencyCode,
                    $request->currencyPrecision, 'test-only-seller', 'test-fees-v2', 'test-tax-v1',
                    [['code' => 'test-fee', 'amount_minor' => 31, 'policy_version' => 'test-fees-v2']],
                    [['code' => 'test-tax', 'amount_minor' => 200, 'policy_version' => 'test-tax-v1']],
                    now()->addMinutes(20)->toImmutable(), 'test-only-policy-evidence-v2', false, false, true);
            }
        });
        $this->app->forgetInstance(EventOrderService::class);
        $orders = app(EventOrderService::class);
        $this->assertHttp(409, fn () => $orders->confirmAndCreateOrder($event['id'], $this->actor, $quote['quote_id'], 'changed-price-order'));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_orders')->where('event_id', $event['id'])->count());
        $this->assertSame('quoted', DB::connection('community_services')->table('community_services.event_order_quotes')->where('id', $quote['quote_id'])->value('status'));
    }

    public function test_order_bound_payment_evidence_mismatch_is_retained_without_issuance(): void
    {
        $prepared = $this->prepareOrder(1, 'mismatch');
        $intentId = (string) Uuid::uuid7();
        $this->linkPaymentIntent($prepared['order']['order_id'], $intentId, (int) $prepared['order']['total_minor'], $prepared['order']['currency_code']);
        $order = $prepared['order'];
        $evidence = new PaymentConfirmationEvidence($intentId, (string) Uuid::uuid7(), $this->organisation, $this->actor,
            'paid_event_ticket', $order['order_id'], (int) $order['total_minor'] + 1, $prepared['event']['currency_code'], (string) Uuid::uuid7(), 'ledger_posted');
        $paymentEvidence = \Mockery::mock(PaymentInternalConfirmationContract::class);
        $paymentEvidence->shouldReceive('confirmationEvidence')->once()->andReturn($evidence);
        $paymentEvidence->shouldNotReceive('acknowledgeFulfilment');
        $this->app->instance(PaymentInternalConfirmationContract::class, $paymentEvidence);
        $serviceActor = new ServiceActor('events', 'SERVICE', [
            'payment.confirmation.read:'.$this->organisation.':paid_event_ticket',
            'payment.fulfilment.ack:'.$this->organisation.':paid_event_ticket'], null);
        $actors = \Mockery::mock(EventsPaymentServiceActorProvider::class);
        $actors->shouldReceive('actorFor')->once()->andReturn($serviceActor);
        $this->app->instance(EventsPaymentServiceActorProvider::class, $actors);
        app(EventPaymentConfirmationConsumer::class)->handle($this->confirmationPayload($prepared, $intentId));

        $db = DB::connection('community_services');
        $inbox = $db->table('community_services.event_payment_inbox')->where('payment_intent_id', $intentId)->first();
        $this->assertSame('blocked_reconciliation', $inbox->state);
        $this->assertNotNull($inbox->verified_evidence);
        $this->assertSame(0, $db->table('community_services.event_tickets')->where('order_id', $order['order_id'])->count());
        $this->assertSame('blocked_reconciliation', $db->table('community_services.event_orders')->where('id', $order['order_id'])->value('fulfilment_state'));
    }

    private function bindTestPricing(string $feeVersion = 'test-fees-v1', int $feeMinor = 30,
        string $taxVersion = 'test-tax-v1', int $taxMinor = 200): void
    {
        $this->app->instance(EventPricingContract::class, new class($feeVersion, $feeMinor, $taxVersion, $taxMinor) implements EventPricingContract
        {
            public function __construct(
                private readonly string $feeVersion,
                private readonly int $feeMinor,
                private readonly string $taxVersion,
                private readonly int $taxMinor,
            ) {}

            public function quote(EventPricingRequest $request): EventPricingBreakdown
            {
                $subtotal = $request->subtotalMinor();

                return new EventPricingBreakdown($subtotal, $this->feeMinor, $this->taxMinor,
                    $subtotal + $this->feeMinor + $this->taxMinor, $request->currencyCode,
                    $request->currencyPrecision, 'test-only-seller', $this->feeVersion, $this->taxVersion,
                    [['code' => 'test-fee', 'amount_minor' => $this->feeMinor, 'policy_version' => $this->feeVersion]],
                    [['code' => 'test-tax', 'amount_minor' => $this->taxMinor, 'policy_version' => $this->taxVersion]],
                    now()->addMinutes(20)->toImmutable(), 'test-only-policy-evidence', false, false, true);
            }
        });
    }

    private function bindSyntheticApprovedPricing(
        string $scopeType,
        string $scopeId,
        string $feeEffectiveFrom = '2026-01-01T00:00:00+00:00',
        string $taxEffectiveFrom = '2026-01-01T00:00:00+00:00',
        ?string $feeEffectiveUntil = null,
        ?string $taxEffectiveUntil = null,
    ): void {
        $this->app->instance(EventPricingContract::class, new class($scopeType, $scopeId, $feeEffectiveFrom, $taxEffectiveFrom, $feeEffectiveUntil, $taxEffectiveUntil) implements EventPricingContract
        {
            public function __construct(
                private readonly string $scopeType,
                private readonly string $scopeId,
                private readonly string $feeEffectiveFrom,
                private readonly string $taxEffectiveFrom,
                private readonly ?string $feeEffectiveUntil,
                private readonly ?string $taxEffectiveUntil,
            ) {}

            public function quote(EventPricingRequest $request): EventPricingBreakdown
            {
                $schedule = [
                    'version' => 'synthetic-fees-approved-v1',
                    'approval_evidence_reference' => 'synthetic-fixture-only',
                    'effective_from' => $this->feeEffectiveFrom,
                    'effective_until' => $this->feeEffectiveUntil,
                    'currency_code' => $request->currencyCode,
                    'calculation_basis' => 'synthetic-basis',
                    'scope' => ['type' => $this->scopeType, 'id' => $this->scopeId],
                    'components' => [['type' => 'fixed', 'value' => '1.00', 'amount_minor' => 100, 'payer' => 'organiser']],
                ];
                $tax = [
                    'version' => 'synthetic-tax-approved-v1',
                    'approval_evidence_reference' => 'synthetic-fixture-only',
                    'effective_from' => $this->taxEffectiveFrom,
                    'effective_until' => $this->taxEffectiveUntil,
                    'jurisdiction' => 'synthetic-jurisdiction',
                    'ticket_classification' => 'synthetic-ticket-classification',
                    'ticket_treatment' => 'synthetic-ticket-treatment',
                    'service_fee_treatment' => 'synthetic-service-fee-treatment',
                    'price_presentation' => 'inclusive',
                    'rates' => [['rate' => '0.075']],
                    'exemptions' => [],
                    'taxable_bases' => ['synthetic-base'],
                    'calculation_order' => ['synthetic-order'],
                    'currency_precision' => $request->currencyPrecision,
                    'rounding_scope' => 'synthetic-line-scope',
                    'rounding_method' => 'synthetic-method',
                    'disclosures' => ['synthetic-disclosure'],
                    'invoice_responsibility' => 'synthetic-party',
                ];

                return new EventPricingBreakdown(
                    $request->subtotalMinor(), 0, 75, $request->subtotalMinor() + 75, $request->currencyCode,
                    $request->currencyPrecision, 'synthetic-verified-seller', 'synthetic-fees-approved-v1',
                    'synthetic-tax-approved-v1', [],
                    [['code' => 'synthetic-tax', 'amount_minor' => 75, 'policy_version' => 'synthetic-tax-approved-v1']],
                    now()->addMinutes(20)->toImmutable(), 'synthetic-fixture-only', true, true, false, 100, $schedule, $tax);
            }
        });
    }

    /** @return array{event:array<string,mixed>,order:array<string,mixed>} */
    private function prepareOrder(int $quantity, string $key): array
    {
        $event = $this->publishedInventoryEvent(3600);
        $this->bindTestPricing();
        $hold = app(EventInventoryHoldService::class)->createHold($event['id'], (string) $event['categories'][0]->id,
            $this->actor, $quantity, $key.'-hold', (string) Uuid::uuid7());
        $service = app(EventOrderService::class);
        $quote = $service->createQuote($event['id'], $this->actor, [$hold['id']], $key.'-quote');
        $order = $service->confirmAndCreateOrder($event['id'], $this->actor, $quote['quote_id'], $key.'-order');

        return ['event' => $event, 'order' => $order];
    }

    private function linkPaymentIntent(string $orderId, string $intentId, int $amount, string $currencyCode = 'BWP'): void
    {
        $payments = \Mockery::mock(PaymentModuleContract::class);
        $payments->shouldReceive('getAvailableOptions')->once()->andReturn([
            new PaymentProviderOption('test_only_wallet', 'Test only wallet', true, true, 1),
        ]);
        $payments->shouldReceive('createPaymentIntent')->once()->andReturn([
            'payment_intent_id' => $intentId, 'payment_intent_reference' => $intentId, 'status' => 'pending',
            'amount_minor' => $amount, 'currency_code' => $currencyCode, 'expires_at' => now()->addMinutes(5)->toImmutable(),
            'correlation_id' => (string) Uuid::uuid7(),
        ]);
        $this->app->instance(PaymentModuleContract::class, $payments);
        app(EventOrderService::class)->createPaymentIntent($orderId, $this->actor, (string) Uuid::uuid7());
    }

    private function bindConfirmation(array $prepared, string $intentId, callable $ack, int $readCalls = 2, int $ackCalls = 2): void
    {
        $order = $prepared['order'];
        $event = $prepared['event'];
        $evidence = new PaymentConfirmationEvidence($intentId, (string) Uuid::uuid7(), $this->organisation, $this->actor,
            'paid_event_ticket', $order['order_id'], (int) $order['total_minor'], $event['currency_code'], (string) Uuid::uuid7(), 'ledger_posted');
        $paymentEvidence = \Mockery::mock(PaymentInternalConfirmationContract::class);
        $paymentEvidence->shouldReceive('confirmationEvidence')->times($readCalls)->andReturn($evidence);
        if ($ackCalls > 0) {
            $paymentEvidence->shouldReceive('acknowledgeFulfilment')->times($ackCalls)->andReturnUsing($ack);
        }
        $this->app->instance(PaymentInternalConfirmationContract::class, $paymentEvidence);
        $serviceActor = new ServiceActor('events', 'SERVICE', [
            'payment.confirmation.read:'.$this->organisation.':paid_event_ticket',
            'payment.fulfilment.ack:'.$this->organisation.':paid_event_ticket'], null);
        $actors = \Mockery::mock(EventsPaymentServiceActorProvider::class);
        $actors->shouldReceive('actorFor')->times($readCalls)->andReturn($serviceActor);
        $this->app->instance(EventsPaymentServiceActorProvider::class, $actors);
    }

    private function confirmationPayload(array $prepared, string $intentId): array
    {
        return ['eventId' => (string) Uuid::uuid7(), 'payment_intent_id' => $intentId,
            'purpose_type' => 'paid_event_ticket', 'purpose_reference' => $prepared['order']['order_id'],
            'organisation_id' => $this->organisation, 'payer_actor_id' => $this->actor,
            'amount_minor' => (int) $prepared['order']['total_minor'], 'currency_code' => $prepared['event']['currency_code']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = (string) Uuid::uuid7();
        $this->organisation = (string) Uuid::uuid7();
        $this->membership = (string) Uuid::uuid7();
        $db = DB::connection('identity');
        $db->table('identity.users')->insert(['id' => $this->actor, 'status' => 'active',
            'actor_type' => 'ADMIN', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('identity.organisations')->insert(['id' => $this->organisation,
            'registry_reference' => 'p25-test-'.Uuid::uuid7(), 'display_name' => 'Test organisation',
            'status' => 'active', 'evidence_reference' => 'test-only', 'requested_by' => $this->actor,
            'reviewed_by' => (string) Uuid::uuid7(), 'created_at' => now(), 'updated_at' => now()]);
        $db->table('identity.organisation_memberships')->insert(['id' => $this->membership,
            'organisation_id' => $this->organisation, 'user_id' => $this->actor, 'status' => 'active',
            'evidence_reference' => 'test-only', 'requested_by' => (string) Uuid::uuid7(),
            'reviewed_by' => (string) Uuid::uuid7(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_default_authority_denies_drafts_even_for_a_member(): void
    {
        $this->assertHttp(403, fn () => app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input()));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_drafts')
            ->where('organisation_id', $this->organisation)->count());
    }

    public function test_draft_is_scoped_and_revocation_stops_access(): void
    {
        $this->allowTestAuthorizer();
        $service = app(EventDraftService::class);
        $draft = $service->create($this->organisation, $this->actor, $this->input());
        $this->assertSame('draft', $draft['status']);
        $this->assertSame(['Example sponsor'], $draft['sponsors']);
        $this->assertCount(2, $draft['categories']);
        $this->assertSame('Supplied entry terms', $draft['entry_policy']);
        $this->assertSame(6, (int) $draft['max_tickets_per_order']);
        $this->assertNull($draft['currency_code']);
        $this->assertHttp(403, fn () => $service->create((string) Uuid::uuid7(), $this->actor, $this->input()));
        DB::connection('identity')->table('identity.organisation_memberships')->where('id', $this->membership)->update(['status' => 'revoked']);
        $this->assertHttp(403, fn () => $service->get($draft['id'], $this->actor));
        $this->assertHttp(403, fn () => $service->update($draft['id'], $this->actor, 1, $this->input()));
        $this->assertFalse(method_exists($service, 'publish'));
    }

    public function test_authoring_supports_explicit_zero_price_free_and_complimentary_categories(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['categories'][0] = [
            'name' => 'Open free admission', 'capacity' => 6, 'allocation_type' => 'free',
            'price_minor' => 0, 'max_per_person' => 2,
        ];
        $input['categories'][1] = [
            'name' => 'Complimentary invitation', 'capacity' => 4, 'allocation_type' => 'complimentary',
            'price_minor' => 0, 'complimentary_quota' => 3, 'max_per_person' => 1,
        ];
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
        $this->assertSame(['complimentary', 'free'], array_map(
            static fn (object $category): string => $category->allocation_type,
            $draft['categories'],
        ));
        $this->assertSame(3, (int) $draft['categories'][0]->complimentary_quota);
        $this->assertSame(1, (int) $draft['categories'][0]->max_per_person);

        $invalid = $input;
        $invalid['categories'][0]['price_minor'] = 100;
        $this->assertValidationError(
            fn () => app(EventDraftService::class)->create($this->organisation, $this->actor, $invalid),
            'categories',
        );
    }

    public function test_free_invitation_registration_is_atomic_idempotent_and_creates_no_financial_effect(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $categoryId = (string) $event['categories'][0]->id;
        DB::connection('community_services')->table('community_services.event_draft_categories')->where('id', $categoryId)
            ->update(['allocation_type' => 'complimentary', 'price_minor' => 0, 'complimentary_quota' => 2, 'max_per_person' => 2]);
        $this->allowPermissions(['events.event.update']);
        $this->grantScope($this->actor, $this->organisation, $event['id'], 'event', EventPermissions::UPDATE, $event['id']);
        $this->allowInvitationRecipientFixture($this->actor);
        $this->allowComplimentaryApprovalFixture();
        $paymentIntentCount = DB::connection('payment')->table('payment.payment_intents')
            ->where('organisation_id', $this->organisation)->count();
        $financialCountsBefore = $this->financialEffectCounts();
        $invitation = app(FreeEventRegistrationService::class)->createInvitation(
            $event['id'], $categoryId, $this->actor, '2027-02-28T09:00:00+02:00', 1,
            $this->actor, 'p26-invite-issue-key', (string) Uuid::uuid7(),
        );
        $invitationSnapshot = json_decode((string) DB::connection('community_services')
            ->table('community_services.event_invitations')->where('id', $invitation['invitation_id'])->value('approval_snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('test-approval-'.$categoryId, $invitationSnapshot['approval_id']);

        $registrations = app(FreeEventRegistrationService::class);
        $this->assertHttp(409, fn () => $registrations->register(
            $event['id'], $categoryId, (string) Uuid::uuid7(), $invitation['token'], 'p26-recipient-mismatch', null,
        ));
        $first = $registrations->register($event['id'], $categoryId, $this->actor, $invitation['token'], 'p26-free-reg-1', null);
        $replay = $registrations->register($event['id'], $categoryId, $this->actor, $invitation['token'], 'p26-free-reg-1', null);
        $this->assertSame($first['registration_id'], $replay['registration_id']);
        $this->assertSame($first['ticket_id'], $replay['ticket_id']);
        $this->assertSame('complimentary', $first['source']);
        $registrationSnapshot = json_decode((string) DB::connection('community_services')
            ->table('community_services.event_free_registrations')->where('id', $first['registration_id'])->value('approval_snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($invitationSnapshot['approval_id'], $registrationSnapshot['approval_id']);
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_invitations')
            ->where('id', $invitation['invitation_id'])->value('uses_count'));
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_tickets')
            ->where('free_registration_id', $first['registration_id'])->count());
        $registrations->revokeInvitation($invitation['invitation_id'], $this->actor, (string) Uuid::uuid7());
        $this->assertSame('valid', DB::connection('community_services')->table('community_services.event_tickets')
            ->where('id', $first['ticket_id'])->value('status'), 'Revoking an invitation does not revoke an issued entitlement.');
        $unusedInvitation = $registrations->createInvitation(
            $event['id'], $categoryId, $this->actor, '2027-02-28T09:00:00+02:00', 2,
            $this->actor, 'p26-unused-revocation-key', (string) Uuid::uuid7(),
        );
        $registrations->revokeInvitation($unusedInvitation['invitation_id'], $this->actor, (string) Uuid::uuid7());
        $this->assertHttp(409, fn () => $registrations->register(
            $event['id'], $categoryId, $this->actor, $unusedInvitation['token'], 'p26-revoked-registration', null,
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_invitations')
            ->where('id', $unusedInvitation['invitation_id'])->value('uses_count'));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_orders')
            ->where('event_id', $event['id'])->count());
        $this->assertSame($paymentIntentCount, DB::connection('payment')->table('payment.payment_intents')
            ->where('organisation_id', $this->organisation)->count());
        $this->assertSame($financialCountsBefore, $this->financialEffectCounts(),
            'Free registration and idempotent replay must not create payment, capture, journal, refund, or settlement records.');
        $auditData = json_encode(DB::connection('community_services')->table('community_services.event_invitation_audit')
            ->where('invitation_id', $invitation['invitation_id'])->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($invitation['token'], $auditData);

        $this->assertHttp(409, fn () => $registrations->register(
            $event['id'], $categoryId, $this->actor, $invitation['token'], 'p26-free-reg-2', null,
        ));
    }

    public function test_expired_and_sold_out_free_registration_fail_without_entitlements(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $categoryId = (string) $event['categories'][0]->id;
        DB::connection('community_services')->table('community_services.event_draft_categories')->where('id', $categoryId)
            ->update(['allocation_type' => 'complimentary', 'price_minor' => 0, 'complimentary_quota' => 2, 'max_per_person' => 2]);
        $this->allowPermissions(['events.event.update']);
        $this->grantScope($this->actor, $this->organisation, $event['id'], 'event', EventPermissions::UPDATE, $event['id']);
        $this->allowInvitationRecipientFixture($this->actor);
        $this->allowComplimentaryApprovalFixture();
        $invitation = app(FreeEventRegistrationService::class)->createInvitation(
            $event['id'], $categoryId, $this->actor, '2027-02-28T09:00:00+02:00', 1,
            $this->actor, 'p26-expired-issue-key', (string) Uuid::uuid7(),
        );
        DB::connection('community_services')->table('community_services.event_invitations')
            ->where('id', $invitation['invitation_id'])->update(['expires_at' => now()->subSecond()]);
        $registrations = app(FreeEventRegistrationService::class);
        $this->assertHttp(409, fn () => $registrations->register(
            $event['id'], $categoryId, $this->actor, $invitation['token'], 'p26-expired-reg', null,
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_free_registrations')
            ->where('event_id', $event['id'])->count());

        DB::connection('community_services')->table('community_services.event_invitations')->where('id', $invitation['invitation_id'])
            ->update(['expires_at' => now()->addHour()]);
        DB::connection('community_services')->table('community_services.event_draft_categories')->where('id', $categoryId)
            ->update(['issued_quantity' => DB::raw('capacity')]);
        $this->assertHttp(409, fn () => $registrations->register(
            $event['id'], $categoryId, $this->actor, $invitation['token'], 'p26-soldout-reg', null,
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_tickets')
            ->where('event_id', $event['id'])->count());
    }

    public function test_free_entitlement_uses_online_gate_admission_without_payment_refund_lookup(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $categoryId = (string) $event['categories'][0]->id;
        DB::connection('community_services')->table('community_services.event_draft_categories')->where('id', $categoryId)
            ->update(['allocation_type' => 'free', 'price_minor' => 0, 'complimentary_quota' => null, 'max_per_person' => 2]);
        $registration = app(FreeEventRegistrationService::class)->register(
            $event['id'], $categoryId, $this->actor, null, 'p26-free-admission', null,
        );
        $gateId = (string) Uuid::uuid7();
        DB::connection('community_services')->table('community_services.event_gates')->insert([
            'id' => $gateId, 'organisation_id' => $this->organisation, 'event_id' => $event['id'],
            'name' => 'P26 test gate', 'status' => 'active', 'created_by_actor_id' => $this->actor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->allowPermissions(['events.gate.validate']);
        $this->grantScope($this->actor, $this->organisation, $gateId, 'gate', EventPermissions::GATE_VALIDATE, $event['id']);
        $refundEligibility = \Mockery::mock(PaymentRefundAdmissionEligibilityContract::class);
        $refundEligibility->shouldNotReceive('hasAdmissionBlockingRefund');
        $this->app->instance(PaymentRefundAdmissionEligibilityContract::class, $refundEligibility);
        $stepUp = \Mockery::mock(ActorStepUpVerifierContract::class);
        $this->app->instance(ActorStepUpVerifierContract::class, $stepUp);
        $ticket = DB::connection('community_services')->table('community_services.event_tickets')
            ->where('id', $registration['ticket_id'])->first();
        $credential = app(AdmissionCredentialVault::class)->recoverForDisplay((string) $ticket->credential_encrypted);

        $result = app(EventAdmissionService::class)->validateOnline($event['id'], $gateId, $this->actor, $credential, null);
        $this->assertSame(['status' => 'admitted', 'category' => (string) $ticket->category_name], $result);
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_attendance')
            ->where('ticket_id', $ticket->id)->count());
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_attendance_audit')
            ->where('ticket_id', $ticket->id)->count());

        $otherAttendee = (string) Uuid::uuid7();
        app(FreeEventRegistrationService::class)->register(
            $event['id'], $categoryId, $otherAttendee, null, 'p26-free-no-show', null,
        );
        DB::connection('community_services')->table('community_services.event_drafts')->where('id', $event['id'])
            ->update(['ends_at' => now()->subMinute()]);
        $this->allowPermissions(['events.attendance.read']);
        $this->grantScope($this->actor, $this->organisation, $event['id'], 'event', EventPermissions::ATTENDANCE_READ);
        $report = app(EventAdmissionService::class)->aggregateAttendance($event['id'], $this->actor);
        $this->assertSame([
            'category' => 'A', 'free_registrations' => 2, 'paid_sales' => 0, 'unique_admissions' => 1,
            'admitted_count' => 1, 'no_shows' => 1, 'no_shows_finalized' => true,
        ], $report[0]);
        $this->assertStringNotContainsString($otherAttendee, json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_complimentary_registration_is_denied_without_authoritative_allocation_approval(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $this->app->instance(ComplimentaryAllocationApprovalContract::class, new DenyComplimentaryAllocationApproval);
        $categoryId = (string) $event['categories'][0]->id;
        DB::connection('community_services')->table('community_services.event_draft_categories')->where('id', $categoryId)
            ->update(['allocation_type' => 'complimentary', 'price_minor' => 0, 'complimentary_quota' => 2, 'max_per_person' => 2]);
        $this->allowPermissions(['events.event.update']);
        $this->grantScope($this->actor, $this->organisation, $event['id'], 'event', EventPermissions::UPDATE, $event['id']);
        $recipientActor = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert(['id' => $recipientActor, 'status' => 'active',
            'actor_type' => 'CUSTOMER', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertHttp(503, fn () => app(FreeEventRegistrationService::class)->createInvitation(
            $event['id'], $categoryId, $recipientActor, '2027-02-28T09:00:00+02:00', 1,
            $this->actor, 'p26-production-deny', (string) Uuid::uuid7(),
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_invitations')
            ->where('event_id', $event['id'])->count());

        $this->assertHttp(503, fn () => app(FreeEventRegistrationService::class)->register(
            $event['id'], $categoryId, $this->actor, null, 'p26-no-approval', null,
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_free_registrations')
            ->where('event_id', $event['id'])->count());
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_tickets')
            ->where('event_id', $event['id'])->count());
    }

    public function test_invalid_complimentary_approval_evidence_denies_registration_without_side_effects(): void
    {
        foreach (['wrong_scope', 'insufficient_quota', 'same_actors', 'expired', 'revoked'] as $case) {
            $event = $this->publishedInventoryEvent(3600);
            $categoryId = (string) $event['categories'][0]->id;
            DB::connection('community_services')->table('community_services.event_draft_categories')->where('id', $categoryId)
                ->update(['allocation_type' => 'complimentary', 'price_minor' => 0, 'complimentary_quota' => 2, 'max_per_person' => 2]);
            $evidence = new ComplimentaryAllocationApprovalEvidence(
                approvalId: 'test-invalid-'.$case, version: 1,
                organisationId: $case === 'wrong_scope' ? (string) Uuid::uuid7() : $this->organisation,
                eventId: $event['id'], categoryId: $categoryId, approvedQuota: $case === 'insufficient_quota' ? 1 : 2,
                requestedByActorId: 'test-requester', approvedByActorId: $case === 'same_actors' ? 'test-requester' : 'test-approver',
                validFrom: new \DateTimeImmutable($case === 'expired' ? '-2 days' : '-1 minute'),
                expiresAt: new \DateTimeImmutable($case === 'expired' ? '-1 day' : '+1 day'),
                revokedAt: $case === 'revoked' ? new \DateTimeImmutable('-1 second') : null,
                provenance: 'fixture://events-invalid-approval-test',
            );
            $this->app->instance(ComplimentaryAllocationApprovalContract::class, new class($evidence) implements ComplimentaryAllocationApprovalContract
            {
                public function __construct(private readonly ComplimentaryAllocationApprovalEvidence $evidence) {}

                public function approvalEvidence(string $organisationId, string $eventId, string $categoryId): ComplimentaryAllocationApprovalEvidence
                {
                    return $this->evidence;
                }
            });

            $this->assertHttp(403, fn () => app(FreeEventRegistrationService::class)->register(
                $event['id'], $categoryId, $this->actor, null, 'invalid-approval-'.$case, null,
            ));
            $this->assertSame(0, DB::connection('community_services')->table('community_services.event_free_registrations')
                ->where('event_id', $event['id'])->count(), $case);
            $this->assertSame(0, DB::connection('community_services')->table('community_services.event_tickets')
                ->where('event_id', $event['id'])->count(), $case);
        }
    }

    public function test_identity_recipient_lookup_and_events_verifier_accept_only_active_customers(): void
    {
        $customerId = (string) Uuid::uuid7();
        $inactiveId = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            ['id' => $customerId, 'status' => 'active', 'actor_type' => 'CUSTOMER', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $inactiveId, 'status' => 'suspended', 'actor_type' => 'CUSTOMER', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $adminId, 'status' => 'active', 'actor_type' => 'ADMIN', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $identity = app(IdentityModuleContract::class);
        $this->assertSame($customerId, $identity->findActiveCustomerActorId($customerId));
        $this->assertNull($identity->findActiveCustomerActorId($inactiveId));
        $this->assertNull($identity->findActiveCustomerActorId($adminId));
        $this->assertNull($identity->findActiveCustomerActorId((string) Uuid::uuid7()));

        $verifier = app(InvitationRecipientVerifier::class);
        $verifier->assertActiveCustomerActor($customerId);
        $this->assertHttp(422, fn () => $verifier->assertActiveCustomerActor($inactiveId));
        $this->assertHttp(422, fn () => $verifier->assertActiveCustomerActor($adminId));
        $this->assertHttp(422, fn () => $verifier->assertActiveCustomerActor((string) Uuid::uuid7()));

        $unavailable = \Mockery::mock(IdentityModuleContract::class);
        $unavailable->shouldReceive('findActiveCustomerActorId')->andThrow(new \RuntimeException('internal identity detail'));
        $this->app->instance(IdentityModuleContract::class, $unavailable);
        $this->app->forgetInstance(InvitationRecipientVerifier::class);
        $this->assertHttp(503, fn () => app(InvitationRecipientVerifier::class)->assertActiveCustomerActor($customerId));
    }

    public function test_capacity_and_version_checks_preserve_existing_draft(): void
    {
        $this->allowTestAuthorizer();
        $service = app(EventDraftService::class);
        $draft = $service->create($this->organisation, $this->actor, $this->input());
        $bad = $this->input();
        $bad['categories'][0]['capacity'] = 99;
        try {
            $service->update($draft['id'], $this->actor, 1, $bad);
            $this->fail('Category capacity must not exceed the event capacity.');
        } catch (ValidationException) {
            $this->assertSame(1, (int) $service->get($draft['id'], $this->actor)['version']);
        }
        $good = $this->input();
        $good['title'] = 'Updated draft';
        $updated = $service->update($draft['id'], $this->actor, 1, $good);
        $this->assertSame(2, (int) $updated['version']);
        $this->assertSame('Updated draft', $updated['title']);
        $this->assertHttp(409, fn () => $service->update($draft['id'], $this->actor, 1, $good));
        $events = DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->orderBy('version')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['created', 'updated'], $events->pluck('action')->all());
        $this->assertStringNotContainsString('Supplied entry terms', json_encode($events, JSON_THROW_ON_ERROR));
    }

    public function test_inventory_hold_denies_draft_unconfigured_and_stale_event_versions(): void
    {
        $this->allowTestAuthorizer();
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $categoryId = (string) $draft['categories'][0]->id;
        $holds = app(EventInventoryHoldService::class);
        $this->assertHttp(409, fn () => $holds->createHold(
            $draft['id'], $categoryId, $this->actor, 1, 'draft-hold', (string) Uuid::uuid7(),
        ));

        DB::connection('community_services')->table('community_services.event_drafts')->where('id', $draft['id'])
            ->update(['workflow_state' => 'published', 'sales_state' => 'active', 'published_version' => 1]);
        $this->assertHttp(409, fn () => $holds->createHold(
            $draft['id'], $categoryId, $this->actor, 1, 'no-duration', (string) Uuid::uuid7(),
        ));

        DB::connection('community_services')->table('community_services.event_drafts')->where('id', $draft['id'])
            ->update(['version' => 2, 'hold_duration_seconds' => 600]);
        $this->assertHttp(409, fn () => $holds->createHold(
            $draft['id'], $categoryId, $this->actor, 1, 'stale-version', (string) Uuid::uuid7(),
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_inventory_holds')
            ->where('event_id', $draft['id'])->count());
    }

    public function test_inventory_holds_are_capacity_bounded_idempotent_and_expire(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $categoryId = (string) $event['categories'][0]->id;
        $service = app(EventInventoryHoldService::class);
        $key = 'hold-'.Uuid::uuid7();
        $hold = $service->createHold($event['id'], $categoryId, $this->actor, 4, $key, (string) Uuid::uuid7());
        $replay = $service->createHold($event['id'], $categoryId, $this->actor, 4, $key, (string) Uuid::uuid7());
        $this->assertSame($hold['id'], $replay['id']);
        $this->assertLessThanOrEqual(strtotime('2027-03-01T09:00:00+02:00'), strtotime((string) $hold['expires_at']));
        $this->assertHttp(409, fn () => $service->createHold(
            $event['id'], $categoryId, $this->actor, 3, 'oversell-'.Uuid::uuid7(), (string) Uuid::uuid7(),
        ));
        $this->assertHttp(409, fn () => $service->createHold(
            $event['id'], $categoryId, $this->actor, 5, $key, (string) Uuid::uuid7(),
        ));

        DB::connection('community_services')->table('community_services.event_inventory_holds')->where('id', $hold['id'])
            ->update(['expires_at' => now()->subSecond()]);
        $next = $service->createHold($event['id'], $categoryId, $this->actor, 6, 'after-expiry-'.Uuid::uuid7(), (string) Uuid::uuid7());
        $this->assertSame('expired', DB::connection('community_services')->table('community_services.event_inventory_holds')
            ->where('id', $hold['id'])->value('status'));
        $this->assertSame(6, (int) $next['quantity']);
    }

    public function test_first_hold_locks_finance_currency_precision_and_expiry_does_not_unlock_it(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $categoryId = (string) $event['categories'][0]->id;
        $hold = app(EventInventoryHoldService::class)->createHold(
            $event['id'], $categoryId, $this->actor, 1, 'currency-lock-first-hold', (string) Uuid::uuid7(),
        );
        $locked = DB::connection('community_services')->table('community_services.event_drafts')->where('id', $event['id'])->first();
        $this->assertNotNull($locked->currency_locked_at);
        $this->assertSame('inventory_hold', $locked->currency_lock_reason);
        $this->assertSame($event['currency_code'], $locked->currency_code);
        $this->assertSame($event['currency_precision_snapshot'], $locked->currency_precision_snapshot);

        DB::connection('community_services')->table('community_services.event_inventory_holds')
            ->where('id', $hold['id'])->update(['expires_at' => now()->subSecond()]);
        app(EventInventoryHoldService::class)->expireDueHolds($event['id']);
        $afterExpiry = DB::connection('community_services')->table('community_services.event_drafts')->where('id', $event['id'])->first();
        $this->assertNotNull($afterExpiry->currency_locked_at);
        $this->assertSame('inventory_hold', $afterExpiry->currency_lock_reason);
    }

    public function test_event_editor_update_preserves_finance_assigned_currency_snapshot(): void
    {
        $this->allowTestAuthorizer();
        $service = app(EventDraftService::class);
        $event = $service->create($this->organisation, $this->actor, $this->input());
        $currency = DB::connection('platform')->table('multi_currency.currencies')->where('is_active', true)
            ->orderBy('code')->first(['code', 'decimal_places', 'updated_at']);
        $this->assertNotNull($currency);
        $assignmentId = (string) Uuid::uuid7();
        DB::connection('community_services')->table('community_services.event_drafts')->where('id', $event['id'])->update([
            'currency_code' => $currency->code, 'currency_precision_snapshot' => $currency->decimal_places,
            'currency_definition_updated_at' => $currency->updated_at, 'currency_assignment_id' => $assignmentId,
        ]);
        DB::connection('community_services')->table('community_services.event_currency_assignments')->insert([
            'id' => $assignmentId, 'event_id' => $event['id'], 'organisation_id' => $this->organisation,
            'currency_code' => $currency->code, 'precision_snapshot' => $currency->decimal_places,
            'currency_definition_updated_at' => $currency->updated_at, 'assigned_by_actor_id' => $this->actor,
            'approval_reference' => 'test-only-fixture', 'policy_version' => 'test-only',
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service->update($event['id'], $this->actor, 1, $this->input());
        $updated = DB::connection('community_services')->table('community_services.event_drafts')->where('id', $event['id'])->first();
        $this->assertSame($currency->code, $updated->currency_code);
        $this->assertSame($assignmentId, $updated->currency_assignment_id);
        $this->assertSame((int) $currency->decimal_places, (int) $updated->currency_precision_snapshot);
    }

    public function test_country_change_before_commitment_invalidates_approvals_and_readiness_version(): void
    {
        $this->allowTestAuthorizer();
        $draftService = app(EventDraftService::class);
        $draft = $draftService->create($this->organisation, $this->actor, $this->input());
        DB::connection('community_services')->table('community_services.event_drafts')->where('id', $draft['id'])->update([
            'workflow_state' => 'approved', 'reviewed_version' => 1, 'price_approved_version' => 1,
            'event_reviewer_id' => (string) Uuid::uuid7(), 'price_approver_id' => (string) Uuid::uuid7(),
            'published_version' => 1, 'sales_state' => 'active',
        ]);
        $updatedInput = $this->input();
        $updatedInput['venue_country_code'] = 'ZA';

        $updated = $draftService->update($draft['id'], $this->actor, 1, $updatedInput);

        $this->assertSame('ZA', $updated['venue_country_code']);
        $this->assertSame(2, (int) $updated['version']);
        $this->assertSame('change_pending', $updated['status']);
        $this->assertNull($updated['event_reviewer_id']);
        $this->assertNull($updated['reviewed_version']);
        $this->assertNull($updated['price_approver_id']);
        $this->assertNull($updated['price_approved_version']);
        $this->assertSame('paused', $updated['sales_state']);
    }

    public function test_physical_event_requires_a_known_iso_country_code(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['venue_country_code'] = 'ZZ';

        $this->assertValidationError(
            fn () => app(EventDraftService::class)->create($this->organisation, $this->actor, $input),
            'venue_country_code',
        );
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_drafts')
            ->where('organisation_id', $this->organisation)->count());
    }

    public function test_approved_event_payment_context_uses_server_country_and_accepted_identifiers(): void
    {
        $this->allowTestAuthorizer();
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $currency = DB::connection('platform')->table('multi_currency.currencies')->where('is_active', true)
            ->orderBy('code')->first(['code']);
        $this->assertNotNull($currency);
        DB::connection('community_services')->table('community_services.event_drafts')->where('id', $draft['id'])->update([
            'workflow_state' => 'approved', 'reviewed_version' => 1, 'price_approved_version' => 1,
            'event_reviewer_id' => (string) Uuid::uuid7(), 'price_approver_id' => (string) Uuid::uuid7(),
            'currency_code' => $currency->code,
        ]);

        $query = DB::connection('community_services')->transaction(
            fn () => app(EventPaymentReadinessQueryFactory::class)->forPublication($draft['id']),
        );

        $this->assertSame($draft['id'], $query->eventId);
        $this->assertSame(1, $query->eventVersion);
        foreach ($query->categoryContexts as $context) {
            $this->assertSame('BW', $context->countryCode);
            $this->assertSame('events', $context->moduleKey);
            $this->assertSame('paid_event_ticket', $context->purposeType);
            $this->assertSame($this->organisation, $context->organisationId);
        }
    }

    public function test_country_change_is_rejected_after_first_inventory_hold(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $categoryId = (string) $event['categories'][0]->id;
        app(EventInventoryHoldService::class)->createHold(
            $event['id'], $categoryId, $this->actor, 1, 'country-locked-hold', (string) Uuid::uuid7(),
        );
        $changed = $this->input();
        $changed['venue_country_code'] = 'ZA';

        $this->assertHttp(409, fn () => app(EventDraftService::class)->update(
            $event['id'], $this->actor, 1, $changed,
        ));
        $stored = DB::connection('community_services')->table('community_services.event_drafts')->where('id', $event['id'])->first();
        $this->assertSame('BW', $stored->venue_country_code);
        $this->assertSame(1, (int) $stored->version);
    }

    public function test_paid_readiness_factory_rejects_online_and_hybrid_events(): void
    {
        $this->allowTestAuthorizer();
        foreach (['online', 'hybrid'] as $format) {
            $input = $this->input();
            $input['event_format'] = $format;
            unset($input['venue_country_code']);
            $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            DB::connection('community_services')->table('community_services.event_drafts')->where('id', $draft['id'])->update([
                'workflow_state' => 'approved', 'reviewed_version' => 1, 'price_approved_version' => 1,
                'event_reviewer_id' => (string) Uuid::uuid7(), 'price_approver_id' => (string) Uuid::uuid7(),
                'currency_code' => 'BWP',
            ]);

            try {
                DB::connection('community_services')->transaction(
                    fn () => app(EventPaymentReadinessQueryFactory::class)->forPublication($draft['id']),
                );
                $this->fail('Unsupported event format must not produce a paid readiness context.');
            } catch (\DomainException $exception) {
                $this->assertStringContainsString('only an approved physical event', $exception->getMessage());
            }
        }
    }

    public function test_currency_definition_guard_requires_transaction_and_reads_on_shared_database(): void
    {
        $currency = DB::connection('platform')->table('multi_currency.currencies')->where('is_active', true)
            ->orderBy('code')->first(['code', 'decimal_places', 'updated_at']);
        $this->assertNotNull($currency);
        $guard = app(CurrencyDefinitionGuard::class);

        $snapshot = DB::connection('community_services')->transaction(
            fn () => $guard->lockAndRead($currency->code, 'community_services'),
        );

        $this->assertNotNull($snapshot);
        $this->assertSame($currency->code, $snapshot->code);
        $this->assertSame((int) $currency->decimal_places, $snapshot->decimalPlaces);
        $this->assertTrue($snapshot->active);
        $this->assertSame((string) $currency->updated_at, $snapshot->updatedAt);
    }

    public function test_currency_assignment_requires_finance_permission_and_exact_event_assignment(): void
    {
        $this->allowTestAuthorizer();
        $event = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $currency = DB::connection('platform')->table('multi_currency.currencies')->where('is_active', true)
            ->orderBy('code')->first(['code']);
        $this->assertNotNull($currency);
        $before = DB::connection('community_services')->table('community_services.event_drafts')->where('id', $event['id'])->first();

        $this->assertHttp(403, fn () => app(EventCurrencyAssignmentService::class)->assign(
            $event['id'], $this->actor, $currency->code, 'fixture-approval', 'test-policy', (string) Uuid::uuid7(),
        ));
        $after = DB::connection('community_services')->table('community_services.event_drafts')->where('id', $event['id'])->first();
        $this->assertSame($before->currency_code, $after->currency_code);
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_currency_assignments')
            ->where('event_id', $event['id'])->count());

        $this->allowPermissions([EventPermissions::CURRENCY_ASSIGN]);
        $this->assertHttp(403, fn () => app(EventCurrencyAssignmentService::class)->assign(
            $event['id'], $this->actor, $currency->code, 'fixture-approval', 'test-policy', (string) Uuid::uuid7(),
        ));
        $this->grantScope($this->actor, $this->organisation, $event['id'], 'event', EventPermissions::CURRENCY_ASSIGN, $event['id']);
        $this->allowPermissions([]);
        $this->assertHttp(403, fn () => app(EventCurrencyAssignmentService::class)->assign(
            $event['id'], $this->actor, $currency->code, 'fixture-approval', 'test-policy', (string) Uuid::uuid7(),
        ));
        $this->allowPermissions([EventPermissions::CURRENCY_ASSIGN]);
        $assignment = app(EventCurrencyAssignmentService::class)->assign(
            $event['id'], $this->actor, $currency->code, 'fixture-approval', 'test-policy', (string) Uuid::uuid7(),
        );
        $this->assertSame($currency->code, $assignment['currency_code']);
        $this->assertSame($this->actor, $assignment['assigned_by_actor_id']);
        $this->assertGreaterThanOrEqual(0, (int) $assignment['precision_snapshot']);
        DB::connection('community_services')->table('community_services.event_drafts')->where('id', $event['id'])
            ->update(['currency_locked_at' => now(), 'currency_lock_reason' => 'durable_order']);
        $this->assertHttp(409, fn () => app(EventCurrencyAssignmentService::class)->assign(
            $event['id'], $this->actor, $currency->code, 'second-reference', 'test-policy', (string) Uuid::uuid7(),
        ));
        $this->assertSame($assignment['id'], DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $event['id'])->value('currency_assignment_id'));
    }

    public function test_precision_change_blocks_hold_and_persists_operator_review_issue(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $categoryId = (string) $event['categories'][0]->id;
        DB::connection('community_services')->table('multi_currency.currencies')->where('code', $event['currency_code'])
            ->update(['decimal_places' => (int) $event['currency_precision_snapshot'] + 1]);

        $this->assertHttp(409, fn () => app(EventInventoryHoldService::class)->createHold(
            $event['id'], $categoryId, $this->actor, 1, 'precision-change-hold', (string) Uuid::uuid7(),
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_inventory_holds')
            ->where('event_id', $event['id'])->count());
        $this->assertSame('CURRENCY_PRECISION_CHANGED', DB::connection('community_services')
            ->table('community_services.event_currency_review_issues')->where('event_id', $event['id'])
            ->whereNull('resolved_at')->value('reason_code'));
        $this->assertSame('paused', DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $event['id'])->value('sales_state'));
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $event['id'])->where('action', 'currency_review_required')->count());
    }

    public function test_inactive_currency_blocks_hold_and_persists_review_issue_audit_and_sales_pause(): void
    {
        $event = $this->publishedInventoryEvent(3600);
        $categoryId = (string) $event['categories'][0]->id;
        DB::connection('community_services')->table('multi_currency.currencies')->where('code', $event['currency_code'])
            ->update(['is_active' => false]);

        $this->assertHttp(409, fn () => app(EventInventoryHoldService::class)->createHold(
            $event['id'], $categoryId, $this->actor, 1, 'inactive-currency-hold', (string) Uuid::uuid7(),
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_inventory_holds')
            ->where('event_id', $event['id'])->count());
        $this->assertSame('CURRENCY_INACTIVE', DB::connection('community_services')
            ->table('community_services.event_currency_review_issues')->where('event_id', $event['id'])
            ->whereNull('resolved_at')->value('reason_code'));
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $event['id'])->where('action', 'currency_review_required')->count());
        $this->assertSame('paused', DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $event['id'])->value('sales_state'));
    }

    public function test_event_edit_cannot_reduce_category_capacity_below_active_hold(): void
    {
        $draft = $this->publishedInventoryEvent(3600);
        $input = $this->input();
        $input['hold_duration_seconds'] = 3600;
        $categoryId = (string) $draft['categories'][0]->id;
        app(EventInventoryHoldService::class)->createHold($draft['id'], $categoryId, $this->actor, 4, 'capacity-lock', (string) Uuid::uuid7());
        $lower = $input;
        $lower['categories'][0]['capacity'] = 3;
        $this->assertValidationError(fn () => app(EventDraftService::class)->update(
            $draft['id'], $this->actor, 1, $lower,
        ), 'categories');
        $this->assertSame(1, (int) app(EventDraftService::class)->get($draft['id'], $this->actor)['version']);
    }

    public function test_authoring_routes_require_authentication_and_both_permission_and_assignment(): void
    {
        $draft = $this->routeDraft();
        $auditCount = DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->count();

        $this->useScopedEventAuthorizers();
        $permissions = ['events.event.read'];
        $identity = \Mockery::mock(IdentityModuleContract::class);
        $identity->shouldReceive('hasPermission')->andReturnUsing(
            static function (string $actorId, string $permission) use (&$permissions): bool {
                return in_array($permission, $permissions, true);
            },
        );
        $this->app->instance(IdentityModuleContract::class, $identity);
        $this->getJson('/v1/events/'.$draft['id'])->assertUnauthorized();
        $this->assertInstanceOf(ScopedEventDraftAuthorizer::class, app(EventDraftAuthorizer::class));
        $this->assertHttp(403, fn () => app(EventDraftService::class)->get($draft['id'], $this->actor));
        $this->requestAs($this->actor)->getJson('/v1/events/'.$draft['id'])->assertForbidden();

        $this->grantScope($this->actor, $this->organisation, $draft['id'], 'event', 'events.event.read');
        $permissions = [];
        $this->requestAs($this->actor)->getJson('/v1/events/'.$draft['id'])->assertForbidden();

        $this->assertSame($auditCount, DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->count());
        $this->assertSame('draft', DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->value('workflow_state'));
    }

    public function test_authoring_routes_deny_cross_organisation_and_cross_event_scope(): void
    {
        $first = $this->routeDraft();
        $sameOrganisationOtherEvent = app(EventDraftService::class)->create(
            $this->organisation, $this->actor, $this->input(),
        );
        $otherOrganisation = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.organisations')->insert([
            'id' => $otherOrganisation, 'registry_reference' => 'p25-route-'.Uuid::uuid7(),
            'display_name' => 'Other test organisation', 'status' => 'active', 'evidence_reference' => 'test-only',
            'requested_by' => $this->actor, 'reviewed_by' => (string) Uuid::uuid7(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('identity')->table('identity.organisation_memberships')->insert([
            'id' => (string) Uuid::uuid7(), 'organisation_id' => $otherOrganisation, 'user_id' => $this->actor,
            'status' => 'active', 'evidence_reference' => 'test-only', 'requested_by' => $this->actor,
            'reviewed_by' => $this->actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->allowTestAuthorizer();
        $otherInput = $this->input();
        $otherInput['title'] = 'Other organisation event';
        $other = app(EventDraftService::class)->create($otherOrganisation, $this->actor, $otherInput);
        $this->useScopedEventAuthorizers();
        $this->allowPermissions(['events.event.read', 'events.event.create']);
        $this->grantScope($this->actor, $this->organisation, $first['id'], 'event', 'events.event.read');

        $this->requestAs($this->actor)->getJson('/v1/events/'.$sameOrganisationOtherEvent['id'])->assertForbidden();
        $this->requestAs($this->actor)->getJson('/v1/events/'.$other['id'])->assertForbidden();
        $this->requestAs($this->actor)->postJson('/v1/events/organisations/'.$otherOrganisation.'/drafts', $this->input())
            ->assertForbidden();
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $other['id'])->count());
    }

    public function test_review_route_rejects_contributor_without_mutating_event(): void
    {
        $draft = $this->routeDraft();
        $this->allowTestWorkflowContracts();
        app(EventWorkflowService::class)->submitForReview($draft['id'], $this->actor, 1);
        $reviewer = (string) Uuid::uuid7();
        DB::connection('community_services')->table('community_services.event_draft_version_contributors')->insert([
            'id' => (string) Uuid::uuid7(), 'event_id' => $draft['id'], 'version' => 1,
            'actor_id' => $reviewer, 'created_at' => now(),
        ]);
        $this->useScopedEventAuthorizers();
        $this->allowPermissions(['events.event.review']);
        $this->grantScope($reviewer, $this->organisation, $draft['id'], 'event', 'events.event.review');
        $before = (array) DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->first();
        $auditCount = DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->count();

        $this->requestAs($reviewer)->postJson('/v1/events/'.$draft['id'].'/approve-review', ['expected_version' => 1])
            ->assertUnprocessable();

        $this->assertSame($before, (array) DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->first());
        $this->assertSame($auditCount, DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->count());
    }

    public function test_review_routes_enforce_distinct_event_and_finance_approvers(): void
    {
        $draft = $this->routeDraft();
        $this->allowTestWorkflowContracts();
        app(EventWorkflowService::class)->submitForReview($draft['id'], $this->actor, 1);
        $reviewer = $this->addMember();
        $finance = $this->addMember();
        $this->useScopedEventAuthorizers();
        $this->allowPermissions(['events.event.review', 'events.price.approve']);
        $this->grantScope($reviewer, $this->organisation, $draft['id'], 'event', 'events.event.review');
        $this->grantScope($reviewer, $this->organisation, $draft['id'], 'event', 'events.price.approve');
        $this->grantScope($finance, $this->organisation, $draft['id'], 'event', 'events.price.approve');

        $this->requestAs($reviewer)->postJson('/v1/events/'.$draft['id'].'/approve-review', ['expected_version' => 1])
            ->assertOk()->assertJsonPath('data.event_reviewer_id', $reviewer);
        $before = (array) DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->first();
        $auditCount = DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->count();
        $this->requestAs($reviewer)->postJson('/v1/events/'.$draft['id'].'/approve-prices', ['expected_version' => 1])
            ->assertUnprocessable();
        $this->assertSame($before, (array) DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->first());
        $this->assertSame($auditCount, DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->count());

        $this->requestAs($finance)->postJson('/v1/events/'.$draft['id'].'/approve-prices', ['expected_version' => 1])
            ->assertOk()->assertJsonPath('data.price_approver_id', $finance);
    }

    public function test_review_routes_deny_invalid_lifecycle_and_stale_versions_without_mutation(): void
    {
        $draft = $this->routeDraft();
        $this->useScopedEventAuthorizers();
        $this->allowPermissions(['events.event.review', 'events.event.submit']);
        $this->grantScope($this->actor, $this->organisation, $draft['id'], 'event', 'events.event.review');
        $this->grantScope($this->actor, $this->organisation, $draft['id'], 'event', 'events.event.submit');
        $before = (array) DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->first();
        $auditCount = DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->count();

        $this->requestAs($this->actor)->postJson('/v1/events/'.$draft['id'].'/approve-review', ['expected_version' => 1])
            ->assertUnprocessable();
        $this->assertSame($before, (array) DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->first());
        $this->assertSame($auditCount, DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->count());

        $this->requestAs($this->actor)->postJson('/v1/events/'.$draft['id'].'/submit-review', ['expected_version' => 1])
            ->assertOk()->assertJsonPath('data.workflow_state', 'pending_review');
        $afterSubmit = (array) DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->first();
        $afterSubmitAudit = DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->count();
        $this->requestAs($this->actor)->postJson('/v1/events/'.$draft['id'].'/approve-review', ['expected_version' => 2])
            ->assertStatus(409);
        $this->assertSame($afterSubmit, (array) DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->first());
        $this->assertSame($afterSubmitAudit, DB::connection('community_services')->table('community_services.event_draft_audit')
            ->where('event_id', $draft['id'])->count());
    }

    public function test_caller_cannot_override_draft_ownership_or_version(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['currency_code'] = 'XXX';
        $input['organisation_id'] = (string) Uuid::uuid7();
        $input['created_by'] = (string) Uuid::uuid7();
        $input['version'] = 700;
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $input);

        $this->assertSame($this->organisation, $draft['organisation_id']);
        $this->assertSame($this->actor, $draft['created_by']);
        $this->assertSame(1, (int) $draft['version']);
        $this->assertNull($draft['currency_code'], 'Currency is assigned by Finance/platform controls, not by the organiser draft.');
        $this->assertSame('draft', $draft['status']);
    }

    public function test_sales_window_must_end_at_or_before_event_start(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['sales_end_at'] = '2027-03-01T10:00:00+02:00';

        try {
            app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            $this->fail('Sales cannot remain open after the event starts.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sales_end_at', $exception->errors());
        }
    }

    public function test_schedule_rejects_invalid_timezone_offsets_and_daylight_saving_gaps(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['starts_at'] = '2027-03-01T09:00:00+00:00';

        try {
            app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            $this->fail('The timestamp offset must match the selected event time zone.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }

        $input = $this->input();
        $input['timezone'] = 'America/New_York';
        $input['starts_at'] = '2027-03-14T02:30:00-05:00';
        $input['ends_at'] = '2027-03-14T04:30:00-04:00';
        $input['sales_start_at'] = '2027-01-01T09:00:00-05:00';
        $input['sales_end_at'] = '2027-03-13T09:00:00-05:00';

        try {
            app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            $this->fail('A nonexistent local time must be rejected rather than shifted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }
    }

    public function test_schedule_rejects_ambiguous_daylight_saving_times(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['timezone'] = 'America/New_York';
        $input['starts_at'] = '2027-11-07T01:30:00-04:00';
        $input['ends_at'] = '2027-11-07T03:30:00-05:00';
        $input['sales_start_at'] = '2027-01-01T09:00:00-05:00';
        $input['sales_end_at'] = '2027-11-06T09:00:00-05:00';

        try {
            app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            $this->fail('An ambiguous local time must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }
    }

    public function test_order_limit_is_required_and_positive(): void
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['max_tickets_per_order'] = 0;

        try {
            app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
            $this->fail('The proposed maximum quantity must be positive.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('max_tickets_per_order', $exception->errors());
        }
    }

    public function test_event_workflow_authority_denies_review_submission_by_default(): void
    {
        $this->allowTestAuthorizer();
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());

        $this->assertHttp(403, fn () => app(EventWorkflowService::class)->submitForReview($draft['id'], $this->actor, 1));
        $this->assertSame('draft', app(EventDraftService::class)->get($draft['id'], $this->actor)['status']);
    }

    public function test_publication_requires_price_and_independent_event_reviews_and_remains_paused(): void
    {
        $this->allowTestAuthorizer();
        $this->allowTestWorkflowContracts();
        $draftInput = $this->input();
        $draftInput['sales_start_at'] = '2026-10-01T09:00:00+02:00';
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $draftInput);
        $priceApprover = $this->addMember();
        $eventReviewer = $this->addMember();
        $publisher = $this->addMember();
        DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->update(['currency_code' => 'BWP']);

        $workflow = app(EventWorkflowService::class);
        $workflow->submitForReview($draft['id'], $this->actor, 1);
        $workflow->approvePrices($draft['id'], $priceApprover, 1);
        $this->assertValidationError(
            fn () => $workflow->publish($draft['id'], $publisher, 1),
            'workflow_state',
        );
        $workflow->approvePublication($draft['id'], $eventReviewer, 1);
        $published = $workflow->publish($draft['id'], $publisher, 1);

        $this->assertSame('published', $published['workflow_state']);
        $this->assertSame('paused', $published['sales_state']);
        $this->assertSame(1, (int) $published['published_version']);
        $this->assertSame('Test event', $published['published_snapshot']['title']);
    }

    public function test_free_only_publication_uses_current_event_review_and_stays_sales_paused(): void
    {
        $this->allowTestAuthorizer();
        $this->allowTestWorkflowContracts();
        $input = $this->input();
        $input['categories'] = [[
            'name' => 'Free admission', 'capacity' => 10, 'allocation_type' => 'free',
            'price_minor' => 0, 'max_per_person' => 1,
        ]];
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
        DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->update(['currency_code' => 'BWP']);
        $reviewer = $this->addMember();
        $publisher = $this->addMember();
        $workflow = app(EventWorkflowService::class);
        $workflow->submitForReview($draft['id'], $this->actor, 1);
        $workflow->approvePublication($draft['id'], $reviewer, 1);

        $published = $workflow->publish($draft['id'], $publisher, 1);

        $this->assertSame('published', $published['workflow_state']);
        $this->assertSame('paused', $published['sales_state']);
        $this->assertNull($published['price_approver_id']);
        $this->assertSame(1, (int) $published['published_version']);
    }

    public function test_paid_publication_still_requires_finance_price_approval(): void
    {
        $this->allowTestAuthorizer();
        $this->allowTestWorkflowContracts();
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $reviewer = $this->addMember();
        $publisher = $this->addMember();
        $workflow = app(EventWorkflowService::class);
        $workflow->submitForReview($draft['id'], $this->actor, 1);
        $workflow->approvePublication($draft['id'], $reviewer, 1);

        $this->assertValidationError(fn () => $workflow->publish($draft['id'], $publisher, 1), 'workflow_state');
        $this->assertSame('approved', DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->value('workflow_state'));
    }

    public function test_free_only_publication_returns_to_full_review_after_paid_category_change(): void
    {
        $this->allowTestAuthorizer();
        $this->allowTestWorkflowContracts();
        $input = $this->input();
        $input['categories'] = [[
            'name' => 'Free admission', 'capacity' => 10, 'allocation_type' => 'free',
            'price_minor' => 0, 'max_per_person' => 1,
        ]];
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
        DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->update(['currency_code' => 'BWP']);
        $reviewer = $this->addMember();
        $publisher = $this->addMember();
        $workflow = app(EventWorkflowService::class);
        $workflow->submitForReview($draft['id'], $this->actor, 1);
        $workflow->approvePublication($draft['id'], $reviewer, 1);
        $workflow->publish($draft['id'], $publisher, 1);

        $paidInput = $this->input();
        $updated = app(EventDraftService::class)->update($draft['id'], $this->actor, 1, $paidInput);

        $this->assertSame('change_pending', $updated['workflow_state']);
        $this->assertSame('paused', $updated['sales_state']);
        $this->assertNull($updated['reviewed_version']);
        $this->assertNull($updated['price_approved_version']);
    }

    public function test_complimentary_category_requires_scoped_approval_before_publication(): void
    {
        $this->allowTestAuthorizer();
        $this->allowTestWorkflowContracts();
        $input = $this->input();
        $input['categories'] = [[
            'name' => 'Invited admission', 'capacity' => 10, 'allocation_type' => 'complimentary',
            'price_minor' => 0, 'complimentary_quota' => 5, 'max_per_person' => 1,
        ]];
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
        DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->update(['currency_code' => 'BWP']);
        $reviewer = $this->addMember();
        $publisher = $this->addMember();
        $categoryId = (string) $draft['categories'][0]->id;
        $approval = \Mockery::mock(ComplimentaryAllocationApprovalContract::class);
        $approval->shouldReceive('approvalEvidence')->once()->with($this->organisation, $draft['id'], $categoryId)
            ->andReturn(new ComplimentaryAllocationApprovalEvidence(
                approvalId: 'test-approval-'.$categoryId, version: 1, organisationId: $this->organisation,
                eventId: $draft['id'], categoryId: $categoryId, approvedQuota: 5,
                requestedByActorId: 'test-requester', approvedByActorId: 'test-approver',
                validFrom: new \DateTimeImmutable('-1 minute'), expiresAt: new \DateTimeImmutable('+1 day'),
                revokedAt: null, provenance: 'fixture://events-feature-test',
            ));
        $this->app->instance(ComplimentaryAllocationApprovalContract::class, $approval);
        $workflow = app(EventWorkflowService::class);
        $workflow->submitForReview($draft['id'], $this->actor, 1);
        $workflow->approvePublication($draft['id'], $reviewer, 1);

        $published = $workflow->publish($draft['id'], $publisher, 1);

        $this->assertSame('published', $published['workflow_state']);
        $this->assertSame('paused', $published['sales_state']);
        $this->assertSame('test-approval-'.$categoryId,
            $published['published_snapshot']['categories'][0]['complimentary_approval_snapshot']['approval_id']);
    }

    public function test_production_publication_readiness_denies_without_finance_currency_assignment(): void
    {
        $this->allowTestAuthorizer();
        $this->allowTestWorkflowContracts();
        $this->app->instance(EventPublicationReadiness::class, new DenyEventPublicationReadiness);
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $priceApprover = $this->addMember();
        $eventReviewer = $this->addMember();
        $publisher = $this->addMember();
        $workflow = app(EventWorkflowService::class);
        $workflow->submitForReview($draft['id'], $this->actor, 1);
        $workflow->approvePrices($draft['id'], $priceApprover, 1);
        $workflow->approvePublication($draft['id'], $eventReviewer, 1);

        $this->assertValidationError(fn () => $workflow->publish($draft['id'], $publisher, 1), 'publication');
        $current = DB::connection('community_services')->table('community_services.event_drafts')->where('id', $draft['id'])->first();
        $this->assertSame('approved', $current->workflow_state);
        $this->assertNull($current->published_version);
    }

    public function test_all_version_contributors_are_excluded_and_approvers_must_be_distinct(): void
    {
        $this->allowTestAuthorizer();
        $this->allowTestWorkflowContracts();
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $editor = $this->addMember();
        app(EventDraftService::class)->update($draft['id'], $editor, 1, $this->input());
        DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->update(['currency_code' => 'BWP']);
        $workflow = app(EventWorkflowService::class);
        $workflow->submitForReview($draft['id'], $editor, 2);

        $this->assertValidationError(
            fn () => $workflow->approvePrices($draft['id'], $this->actor, 2),
            'actor_id',
        );
        $this->assertValidationError(
            fn () => $workflow->approvePrices($draft['id'], $editor, 2),
            'actor_id',
        );
        $this->assertValidationError(
            fn () => $workflow->approvePublication($draft['id'], $editor, 2),
            'actor_id',
        );

        $financeApprover = $this->addMember();
        $eventReviewer = $this->addMember();
        $publisher = $this->addMember();
        $workflow->approvePrices($draft['id'], $financeApprover, 2);
        $this->assertValidationError(
            fn () => $workflow->approvePublication($draft['id'], $financeApprover, 2),
            'actor_id',
        );
        $workflow->approvePublication($draft['id'], $eventReviewer, 2);
        $approved = $workflow->publish($draft['id'], $publisher, 2);

        $contributors = DB::connection('community_services')->table('community_services.event_draft_version_contributors')
            ->where('event_id', $draft['id'])->where('version', 2)->pluck('actor_id')->all();
        $this->assertEqualsCanonicalizing([$this->actor, $editor], $contributors);
        $this->assertSame('published', $approved['workflow_state']);
        $this->assertNotSame($approved['event_reviewer_id'], $approved['price_approver_id']);
    }

    public function test_finance_price_approval_can_follow_event_review_when_approvers_are_distinct(): void
    {
        $this->allowTestAuthorizer();
        $this->allowTestWorkflowContracts();
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->update(['currency_code' => 'BWP']);
        $financeApprover = $this->addMember();
        $eventReviewer = $this->addMember();
        $publisher = $this->addMember();
        $workflow = app(EventWorkflowService::class);
        $workflow->submitForReview($draft['id'], $this->actor, 1);
        $workflow->approvePublication($draft['id'], $eventReviewer, 1);
        $workflow->approvePrices($draft['id'], $financeApprover, 1);
        $approved = $workflow->publish($draft['id'], $publisher, 1);

        $this->assertSame('published', $approved['workflow_state']);
        $this->assertNotSame($approved['event_reviewer_id'], $approved['price_approver_id']);
    }

    public function test_event_scope_requires_both_permission_and_exact_event_assignment(): void
    {
        $this->allowTestAuthorizer();
        $service = app(EventDraftService::class);
        $eventA = $service->create($this->organisation, $this->actor, $this->input());
        $eventB = $service->create($this->organisation, $this->actor, $this->input());
        $this->allowPermissions(['events.event.read']);
        $this->assertHttp(403, fn () => app(ScopedEventAuthorization::class)->assertEvent(
            $this->actor, $this->organisation, $eventA['id'], 'events.event.read',
        ));

        $this->grantScope($this->actor, $this->organisation, $eventB['id'], 'event', 'events.event.read');
        $this->assertHttp(403, fn () => app(ScopedEventAuthorization::class)->assertEvent(
            $this->actor, $this->organisation, $eventA['id'], 'events.event.read',
        ));
        app(ScopedEventAuthorization::class)->assertEvent(
            $this->actor, $this->organisation, $eventB['id'], 'events.event.read',
        );
        $this->assertHttp(404, fn () => app(ScopedEventAuthorization::class)->assertEvent(
            $this->actor, (string) Uuid::uuid7(), $eventB['id'], 'events.event.read',
        ));

        $this->allowPermissions([]);
        $this->assertHttp(403, fn () => app(ScopedEventAuthorization::class)->assertEvent(
            $this->actor, $this->organisation, $eventB['id'], 'events.event.read',
        ));
    }

    public function test_event_creation_requires_organisation_permission_and_assignment(): void
    {
        $this->allowPermissions(['events.event.create']);
        $authorization = app(ScopedEventAuthorization::class);
        $this->assertHttp(403, fn () => $authorization->assertOrganisation(
            $this->actor, $this->organisation, 'events.event.create',
        ));
        $this->grantScope($this->actor, $this->organisation, $this->organisation, 'organisation', 'events.event.create');
        $authorization->assertOrganisation($this->actor, $this->organisation, 'events.event.create');
        $this->allowPermissions([]);
        $this->assertHttp(403, fn () => app(ScopedEventAuthorization::class)->assertOrganisation(
            $this->actor, $this->organisation, 'events.event.create',
        ));
    }

    public function test_assignment_write_path_is_scoped_idempotent_and_audited(): void
    {
        $this->allowPermissions([EventPermissions::ASSIGNMENT_GRANT, EventPermissions::ASSIGNMENT_REVOKE]);
        $this->designateAssignmentAdministrator($this->actor, 'organisation');
        $target = $this->addMember();
        $service = app(EventAssignmentManagementService::class);

        $first = $service->grantOrganisationAssignment(
            $this->organisation, $target, EventPermissions::CREATE, $this->actor, 'test_grant', (string) Uuid::uuid7(),
        );
        $replay = $service->grantOrganisationAssignment(
            $this->organisation, $target, EventPermissions::CREATE, $this->actor, 'test_grant', (string) Uuid::uuid7(),
        );

        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_authority_assignment_audit')
            ->where('assignment_id', $first['id'])->count());

        $service->revokeAssignment($first['id'], $this->actor, 'test_revoke', (string) Uuid::uuid7());
        $again = $service->revokeAssignment($first['id'], $this->actor, 'test_revoke', (string) Uuid::uuid7());
        $this->assertSame($this->actor, $again['revoked_by_actor_id']);
        $this->assertSame($this->actor, DB::connection('community_services')
            ->table('community_services.event_authority_assignments')->where('id', $first['id'])->value('granted_by_actor_id'));
        $this->assertSame(2, DB::connection('community_services')->table('community_services.event_authority_assignment_audit')
            ->where('assignment_id', $first['id'])->count());
    }

    public function test_revoking_administrator_stops_new_grants_without_cascading_existing_assignments(): void
    {
        $this->allowPermissions([EventPermissions::ASSIGNMENT_GRANT, EventPermissions::ASSIGNMENT_REVOKE]);
        $this->designateAssignmentAdministrator($this->actor, 'organisation');
        $secondAdministrator = $this->addMember();
        $secondDesignation = $this->designateAssignmentAdministrator($secondAdministrator, 'organisation');
        $target = $this->addMember();
        $service = app(EventAssignmentManagementService::class);
        $assignment = $service->grantOrganisationAssignment(
            $this->organisation, $target, EventPermissions::CREATE, $this->actor, 'test_grant', (string) Uuid::uuid7(),
        );

        $service->revokeAdministrator($secondDesignation, $this->actor, 'authority_revoked', (string) Uuid::uuid7());
        $this->assertHttp(403, fn () => $service->grantOrganisationAssignment(
            $this->organisation, $this->addMember(), EventPermissions::CREATE, $secondAdministrator,
            'after_revocation', (string) Uuid::uuid7(),
        ));
        $this->assertNull(DB::connection('community_services')->table('community_services.event_authority_assignments')
            ->where('id', $assignment['id'])->value('revoked_at'));
        $this->assertSame($this->actor, DB::connection('community_services')->table('community_services.event_authority_assignments')
            ->where('id', $assignment['id'])->value('granted_by_actor_id'));
    }

    public function test_assignment_authority_rejects_self_assignment_and_gate_grants_without_gate_registry(): void
    {
        $this->allowPermissions([
            EventPermissions::ASSIGNMENT_GRANT, EventPermissions::ASSIGNMENT_REVOKE,
            EventPermissions::GATE_ASSIGNMENT_MANAGE, EventPermissions::GATE_VALIDATE,
        ]);
        $this->designateAssignmentAdministrator($this->actor, 'organisation');
        $service = app(EventAssignmentManagementService::class);
        $this->allowTestAuthorizer();
        $event = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $this->assertHttp(403, fn () => $service->delegateAdministrator(
            $this->organisation, $this->actor, 'organisation', 'self-assignment-test', $this->actor, (string) Uuid::uuid7(),
        ));
        $this->assertValidationError(fn () => $service->grantEventAssignment(
            $event['id'], $this->addMember(), EventPermissions::GATE_VALIDATE,
            $this->actor, 'no_gate_registry', (string) Uuid::uuid7(),
        ), 'permission_slug');
    }

    public function test_organisation_assignment_authority_cannot_grant_finance_permissions(): void
    {
        $this->allowPermissions([EventPermissions::ASSIGNMENT_GRANT, EventPermissions::ASSIGNMENT_REVOKE]);
        $this->designateAssignmentAdministrator($this->actor, 'organisation');
        $this->allowTestAuthorizer();
        $event = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $target = $this->addMember();
        $this->assertHttp(403, fn () => app(EventAssignmentManagementService::class)->grantEventAssignment(
            $event['id'], $target, EventPermissions::PRICE_APPROVE,
            $this->actor, 'finance_scope_required', (string) Uuid::uuid7(),
        ));
    }

    public function test_event_gate_manager_can_manage_only_validation_assignments_in_their_event(): void
    {
        $this->allowPermissions([EventPermissions::GATE_ASSIGNMENT_MANAGE, EventPermissions::GATE_VALIDATE]);
        $this->allowTestAuthorizer();
        $eventA = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $eventB = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $this->grantScope($this->actor, $this->organisation, $eventA['id'], 'event', EventPermissions::GATE_ASSIGNMENT_MANAGE);
        $service = app(EventAssignmentManagementService::class);
        $gateA = $service->createGate($eventA['id'], 'North', $this->actor, (string) Uuid::uuid7());
        $listed = $service->listGates($eventA['id'], $this->actor);
        $this->assertSame(['id', 'event_id', 'name', 'status'], array_keys($listed[0]));
        $this->assertSame($gateA['id'], $listed[0]['id']);
        $managerB = $this->addMember();
        $this->grantScope($managerB, $this->organisation, $eventB['id'], 'event', EventPermissions::GATE_ASSIGNMENT_MANAGE);
        $gateB = $service->createGate($eventB['id'], 'South', $managerB, (string) Uuid::uuid7());
        $scanner = $this->addMember();

        $assignment = $service->grantGateValidation($gateA['id'], $scanner, $this->actor, 'gate_assignment', (string) Uuid::uuid7());
        $this->assertSame('gate', $assignment['scope_type']);
        $this->assertSame($gateA['id'], $assignment['scope_id']);
        $this->assertSame($eventA['id'], $assignment['event_id']);
        $this->assertHttp(403, fn () => $service->grantGateValidation(
            $gateB['id'], $this->addMember(), $this->actor, 'cross_event_gate', (string) Uuid::uuid7(),
        ));
        $scannerB = $this->addMember();
        $assignmentB = $service->grantGateValidation($gateB['id'], $scannerB, $managerB, 'gate_assignment', (string) Uuid::uuid7());
        $this->assertHttp(403, fn () => $service->revokeAssignment(
            $assignmentB['id'], $this->actor, 'cross_event_revoke', (string) Uuid::uuid7(),
        ));

        $service->revokeAssignment($assignment['id'], $this->actor, 'gate_removed', (string) Uuid::uuid7());
        $this->assertNotNull(DB::connection('community_services')->table('community_services.event_authority_assignments')
            ->where('id', $assignment['id'])->value('revoked_at'));
    }

    public function test_gate_manager_cannot_delegate_validation_to_self(): void
    {
        $this->allowPermissions([EventPermissions::GATE_ASSIGNMENT_MANAGE, EventPermissions::GATE_VALIDATE]);
        $this->allowTestAuthorizer();
        $event = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $this->grantScope($this->actor, $this->organisation, $event['id'], 'event', EventPermissions::GATE_ASSIGNMENT_MANAGE);
        $gate = app(EventAssignmentManagementService::class)->createGate($event['id'], 'Main', $this->actor, (string) Uuid::uuid7());

        $this->assertHttp(403, fn () => app(EventAssignmentManagementService::class)->grantGateValidation(
            $gate['id'], $this->actor, $this->actor, 'self_assign', (string) Uuid::uuid7(),
        ));
    }

    public function test_bootstrap_rejects_duplicate_approvers_and_records_no_authority(): void
    {
        $service = app(EventAssignmentManagementService::class);
        $this->assertValidationError(fn () => $service->bootstrapAdministrator(
            $this->organisation, $this->addMember(), 'organisation', $this->actor, $this->actor,
            'unverified-test-reference', $this->actor, (string) Uuid::uuid7(),
        ), 'approvers');
        $target = $this->addMember();
        $approverTwo = $this->addMember();
        $this->assertHttp(403, fn () => $service->bootstrapAdministrator(
            $this->organisation, $target, 'organisation', $this->actor, $approverTwo,
            'self-operator-test', $target, (string) Uuid::uuid7(),
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_assignment_administrators')->count());
    }

    public function test_bootstrap_records_two_approvers_and_is_idempotent_for_the_same_evidence(): void
    {
        $this->allowPermissions([
            EventPermissions::ASSIGNMENT_BOOTSTRAP_EXECUTE, EventPermissions::ASSIGNMENT_BOOTSTRAP_APPROVE,
            EventPermissions::ASSIGNMENT_GRANT, EventPermissions::ASSIGNMENT_REVOKE,
        ]);
        $target = $this->addMember();
        $operator = $this->addMember();
        $approverTwo = $this->addMember();
        $approvalReference = 'test-approved-bootstrap-'.Uuid::uuid7();
        // Explicit test fixture only; operational binding is the deny-by-default verifier.
        app()->instance(BootstrapApprovalEvidenceVerifier::class, new class implements BootstrapApprovalEvidenceVerifier
        {
            public function assertApproved(string $organisationId, string $administratorActorId, string $authorityType, array $approverActorIds, string $approvalReference): void {}
        });
        $service = app(EventAssignmentManagementService::class);

        $first = $service->bootstrapAdministrator(
            $this->organisation, $target, 'organisation', $this->actor, $approverTwo,
            $approvalReference, $operator, (string) Uuid::uuid7(),
        );
        $replay = $service->bootstrapAdministrator(
            $this->organisation, $target, 'organisation', $this->actor, $approverTwo,
            $approvalReference, $operator, (string) Uuid::uuid7(),
        );

        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame($this->actor, $first['approver_one_actor_id']);
        $this->assertSame($approverTwo, $first['approver_two_actor_id']);
        $this->assertSame($approvalReference, $first['approval_reference']);
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_assignment_administrator_audit')
            ->where('administrator_record_id', $first['id'])->count());
    }

    public function test_bootstrap_reference_string_alone_cannot_create_operational_authority(): void
    {
        $this->allowPermissions([
            EventPermissions::ASSIGNMENT_BOOTSTRAP_EXECUTE, EventPermissions::ASSIGNMENT_BOOTSTRAP_APPROVE,
            EventPermissions::ASSIGNMENT_GRANT, EventPermissions::ASSIGNMENT_REVOKE,
        ]);
        $target = $this->addMember();
        $operator = $this->addMember();
        $approverTwo = $this->addMember();

        $this->assertHttp(403, fn () => app(EventAssignmentManagementService::class)->bootstrapAdministrator(
            $this->organisation, $target, 'organisation', $this->actor, $approverTwo,
            'valid-looking-but-unverified-reference', $operator, (string) Uuid::uuid7(),
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_assignment_administrators')
            ->where('organisation_id', $this->organisation)->count());
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_assignment_administrator_audit')->count());
    }

    public function test_gate_scope_is_exact_and_revocation_denies_next_validation(): void
    {
        $this->allowTestAuthorizer();
        $event = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $gateA = (string) Uuid::uuid7();
        $gateB = (string) Uuid::uuid7();
        foreach ([[$gateA, 'Gate A'], [$gateB, 'Gate B']] as [$gateId, $name]) {
            DB::connection('community_services')->table('community_services.event_gates')->insert([
                'id' => $gateId, 'organisation_id' => $this->organisation, 'event_id' => $event['id'],
                'name' => $name, 'status' => 'active', 'created_by_actor_id' => $this->actor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->allowPermissions(['events.gate.validate']);
        $assignmentId = $this->grantScope($this->actor, $this->organisation, $gateA, 'gate', 'events.gate.validate', $event['id']);

        $this->assertHttp(403, fn () => DB::connection('community_services')->transaction(
            fn () => app(ScopedEventAuthorization::class)->assertGate(
                $this->actor, $this->organisation, $event['id'], $gateB, 'events.gate.validate',
            ),
        ));
        DB::connection('community_services')->transaction(fn () => app(ScopedEventAuthorization::class)->assertGate(
            $this->actor, $this->organisation, $event['id'], $gateA, 'events.gate.validate',
        ));
        DB::connection('community_services')->table('community_services.event_authority_assignments')
            ->where('id', $assignmentId)->update(['revoked_at' => now(), 'revoked_by_actor_id' => $this->actor]);
        $this->assertHttp(403, fn () => DB::connection('community_services')->transaction(
            fn () => app(ScopedEventAuthorization::class)->assertGate(
                $this->actor, $this->organisation, $event['id'], $gateA, 'events.gate.validate',
            ),
        ));
    }

    public function test_gate_assignment_manager_can_delegate_validation_only_and_not_to_self(): void
    {
        $this->allowTestAuthorizer();
        $event = app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
        $this->allowPermissions(['events.gate.assignment.manage']);
        $this->grantScope($this->actor, $this->organisation, $event['id'], 'event', 'events.gate.assignment.manage');
        $authorization = app(ScopedEventAuthorization::class);

        $authorization->assertCanDelegateGateValidation(
            $this->actor, (string) Uuid::uuid7(), $this->organisation, $event['id'], 'events.gate.validate',
        );
        foreach ([
            'events.event.review', 'events.price.approve', 'events.event.publish',
            'events.gate.assignment.manage',
        ] as $forbiddenDelegation) {
            $this->assertHttp(403, fn () => $authorization->assertCanDelegateGateValidation(
                $this->actor, (string) Uuid::uuid7(), $this->organisation, $event['id'], $forbiddenDelegation,
            ));
        }
        $this->assertHttp(403, fn () => $authorization->assertCanDelegateGateValidation(
            $this->actor, $this->actor, $this->organisation, $event['id'], 'events.gate.validate',
        ));
    }

    public function test_online_admission_is_one_time_private_and_audited_atomically(): void
    {
        $fixture = $this->issueAdmissionTicket();
        $this->allowPermissions(['events.gate.validate', 'events.attendance.read']);
        $this->grantScope($this->actor, $this->organisation, $fixture['gate_id'], 'gate', EventPermissions::GATE_VALIDATE, $fixture['event_id']);
        $this->grantScope($this->actor, $this->organisation, $fixture['event_id'], 'event', EventPermissions::ATTENDANCE_READ);
        $service = app(EventAdmissionService::class);

        $accepted = $service->validateOnline($fixture['event_id'], $fixture['gate_id'], $this->actor, $fixture['credential'], (string) Uuid::uuid7());
        $repeated = $service->validateOnline($fixture['event_id'], $fixture['gate_id'], $this->actor, $fixture['credential'], (string) Uuid::uuid7());
        $this->assertSame(['status' => 'admitted', 'category' => 'A'], $accepted);
        $this->assertSame(['status' => 'already_admitted', 'category' => 'A'], $repeated);

        $db = DB::connection('community_services');
        $this->assertSame(1, $db->table('community_services.event_attendance')->where('ticket_id', $fixture['ticket_id'])->count());
        $this->assertSame(1, $db->table('community_services.event_attendance_audit')->where('ticket_id', $fixture['ticket_id'])->count());
        $audit = (array) $db->table('community_services.event_attendance_audit')->where('ticket_id', $fixture['ticket_id'])->first();
        $this->assertArrayNotHasKey('credential', $audit);
        $this->assertStringNotContainsString($fixture['credential'], json_encode($audit, JSON_THROW_ON_ERROR));
    }

    public function test_attendance_and_audit_rollback_together_if_audit_persistence_fails(): void
    {
        $fixture = $this->issueAdmissionTicket();
        $this->allowPermissions(['events.gate.validate']);
        $this->grantScope($this->actor, $this->organisation, $fixture['gate_id'], 'gate', EventPermissions::GATE_VALIDATE, $fixture['event_id']);
        $db = DB::connection('community_services');
        $this->assertSame('pgsql', $db->getDriverName());
        $db->statement("CREATE OR REPLACE FUNCTION community_services.p25_test_reject_attendance_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'injected attendance audit failure'; END $$");
        $db->statement('CREATE TRIGGER p25_test_reject_attendance_audit BEFORE INSERT ON community_services.event_attendance_audit FOR EACH ROW EXECUTE FUNCTION community_services.p25_test_reject_attendance_audit()');
        try {
            try {
                app(EventAdmissionService::class)->validateOnline($fixture['event_id'], $fixture['gate_id'], $this->actor, $fixture['credential'], null);
                $this->fail('Audit persistence failure must roll back admission.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('injected attendance audit failure', $exception->getMessage());
            }
        } finally {
            $db->statement('DROP TRIGGER IF EXISTS p25_test_reject_attendance_audit ON community_services.event_attendance_audit');
            $db->statement('DROP FUNCTION IF EXISTS community_services.p25_test_reject_attendance_audit()');
        }
        $this->assertSame(0, $db->table('community_services.event_attendance')->where('ticket_id', $fixture['ticket_id'])->count());
        $this->assertSame(0, $db->table('community_services.event_attendance_audit')->where('ticket_id', $fixture['ticket_id'])->count());
    }

    public function test_each_entitlement_in_a_multi_ticket_order_has_independent_admission_state(): void
    {
        $fixture = $this->issueAdmissionTicket(3);
        $this->allowPermissions(['events.gate.validate']);
        $this->grantScope($this->actor, $this->organisation, $fixture['gate_id'], 'gate', EventPermissions::GATE_VALIDATE, $fixture['event_id']);
        $db = DB::connection('community_services');
        $tickets = $db->table('community_services.event_tickets')->where('order_id', $fixture['order_id'])->orderBy('id')->get();
        $this->assertCount(3, $tickets);
        $credential = app(AdmissionCredentialVault::class)
            ->recoverForDisplay((string) $tickets[0]->credential_encrypted);
        $this->assertSame(['status' => 'admitted', 'category' => 'A'], app(EventAdmissionService::class)->validateOnline(
            $fixture['event_id'], $fixture['gate_id'], $this->actor, $credential, null,
        ));
        $this->assertSame(1, $db->table('community_services.event_attendance as a')
            ->join('community_services.event_tickets as t', 't.id', '=', 'a.ticket_id')
            ->where('t.order_id', $fixture['order_id'])->count());
        $this->assertSame(2, $db->table('community_services.event_tickets as t')
            ->leftJoin('community_services.event_attendance as a', 'a.ticket_id', '=', 't.id')
            ->where('t.order_id', $fixture['order_id'])->whereNull('a.id')->count());
    }

    public function test_gate_admission_requires_permission_and_exact_gate_assignment_and_denies_revoked_ticket(): void
    {
        $fixture = $this->issueAdmissionTicket();
        $this->allowPermissions(['events.gate.validate']);
        $service = app(EventAdmissionService::class);
        $this->assertHttp(403, fn () => $service->validateOnline(
            $fixture['event_id'], $fixture['gate_id'], $this->actor, $fixture['credential'], null,
        ));
        $this->grantScope($this->actor, $this->organisation, $fixture['gate_id'], 'gate', EventPermissions::GATE_VALIDATE, $fixture['event_id']);
        $wrongGate = (string) Uuid::uuid7();
        DB::connection('community_services')->table('community_services.event_gates')->insert([
            'id' => $wrongGate, 'organisation_id' => $this->organisation, 'event_id' => $fixture['event_id'],
            'name' => 'Other gate', 'status' => 'active', 'created_by_actor_id' => $this->actor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertHttp(403, fn () => $service->validateOnline(
            $fixture['event_id'], $wrongGate, $this->actor, $fixture['credential'], null,
        ));

        $otherEventFixture = $this->issueAdmissionTicket();
        $this->assertHttp(404, fn () => $service->validateOnline(
            $fixture['event_id'], $otherEventFixture['gate_id'], $this->actor, $fixture['credential'], null,
        ));

        $otherOrganisation = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.organisations')->insert([
            'id' => $otherOrganisation, 'registry_reference' => 'p25-test-'.Uuid::uuid7(),
            'display_name' => 'Other test organisation', 'status' => 'active', 'evidence_reference' => 'test-only',
            'requested_by' => $this->actor, 'reviewed_by' => (string) Uuid::uuid7(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('identity')->table('identity.organisation_memberships')->insert([
            'id' => (string) Uuid::uuid7(), 'organisation_id' => $otherOrganisation, 'user_id' => $this->actor,
            'status' => 'active', 'evidence_reference' => 'test-only', 'requested_by' => (string) Uuid::uuid7(),
            'reviewed_by' => (string) Uuid::uuid7(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $originalOrganisation = $this->organisation;
        try {
            $this->organisation = $otherOrganisation;
            $otherOrganisationFixture = $this->issueAdmissionTicket();
        } finally {
            $this->organisation = $originalOrganisation;
        }
        $this->assertHttp(404, fn () => $service->validateOnline(
            $fixture['event_id'], $otherOrganisationFixture['gate_id'], $this->actor, $fixture['credential'], null,
        ));

        DB::connection('community_services')->table('community_services.event_tickets')->where('id', $fixture['ticket_id'])->update(['status' => 'revoked']);
        $this->assertSame(['status' => 'denied', 'category' => 'A'], $service->validateOnline(
            $fixture['event_id'], $fixture['gate_id'], $this->actor, $fixture['credential'], null,
        ));
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_attendance')->where('ticket_id', $fixture['ticket_id'])->count());
    }

    public function test_ticket_credentials_are_purchaser_scoped_and_attendance_report_is_aggregate_only(): void
    {
        $fixture = $this->issueAdmissionTicket();
        $this->allowPermissions(['events.gate.validate', 'events.attendance.read']);
        $this->grantScope($this->actor, $this->organisation, $fixture['gate_id'], 'gate', EventPermissions::GATE_VALIDATE, $fixture['event_id']);
        $this->grantScope($this->actor, $this->organisation, $fixture['event_id'], 'event', EventPermissions::ATTENDANCE_READ);
        $this->assertSame($fixture['credential'], app(EventAdmissionService::class)->ticketForPurchaser($fixture['ticket_id'], $this->actor)['credential']);
        $otherPurchaser = (string) Uuid::uuid7();
        $this->assertHttp(404, fn () => app(EventAdmissionService::class)->ticketForPurchaser($fixture['ticket_id'], $otherPurchaser));
        $this->assertSame([], app(EventAdmissionService::class)->ticketsForPurchaser($otherPurchaser));
        app(EventAdmissionService::class)->validateOnline($fixture['event_id'], $fixture['gate_id'], $this->actor, $fixture['credential'], null);
        $report = app(EventAdmissionService::class)->aggregateAttendance($fixture['event_id'], $this->actor);
        $this->assertSame([
            ['category' => 'A', 'free_registrations' => 0, 'paid_sales' => 1, 'unique_admissions' => 1,
                'admitted_count' => 1, 'no_shows' => null, 'no_shows_finalized' => false],
            ['category' => 'B', 'free_registrations' => 0, 'paid_sales' => 0, 'unique_admissions' => 0,
                'admitted_count' => 0, 'no_shows' => null, 'no_shows_finalized' => false],
        ], $report);
        $this->assertStringNotContainsString($fixture['ticket_id'], json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($this->actor, json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_ticket_credential_replacement_requires_identity_step_up_and_invalidates_old_secret(): void
    {
        $fixture = $this->issueAdmissionTicket();
        $this->allowPermissions(['events.gate.validate']);
        $sessionId = (string) Uuid::uuid7();
        $stepUp = \Mockery::mock(ActorStepUpVerifierContract::class);
        $stepUp->shouldReceive('verifyStepUpForActor')->once()
            ->with('verified-test-token', 'event_ticket.credential.replace:'.$fixture['ticket_id'], $this->actor, $sessionId)
            ->andReturnTrue();
        $this->app->instance(ActorStepUpVerifierContract::class, $stepUp);
        $service = app(EventAdmissionService::class);
        $key = 'replace-'.Uuid::uuid7();
        $this->assertSame(['ticket_id' => $fixture['ticket_id'], 'status' => 'rotated'],
            $service->replaceCredential($fixture['ticket_id'], $this->actor, $sessionId, 'verified-test-token', $key));
        $newCredential = $service->ticketForPurchaser($fixture['ticket_id'], $this->actor)['credential'];
        $this->assertNotSame($fixture['credential'], $newCredential);
        $this->assertSame(['ticket_id' => $fixture['ticket_id'], 'status' => 'unchanged'],
            $service->replaceCredential($fixture['ticket_id'], $this->actor, $sessionId, 'verified-test-token', $key));
        $this->assertHttp(409, fn () => $service->replaceCredential(
            $fixture['ticket_id'], $this->actor, $sessionId, 'verified-test-token', 'replacement-replay-'.Uuid::uuid7(),
        ));

        $this->grantScope($this->actor, $this->organisation, $fixture['gate_id'], 'gate', EventPermissions::GATE_VALIDATE, $fixture['event_id']);
        $this->assertSame(['status' => 'denied', 'category' => null], $service->validateOnline(
            $fixture['event_id'], $fixture['gate_id'], $this->actor, $fixture['credential'], null,
        ));
        $this->assertSame(['status' => 'admitted', 'category' => 'A'], $service->validateOnline(
            $fixture['event_id'], $fixture['gate_id'], $this->actor, $newCredential, null,
        ));
        $this->assertHttp(409, fn () => $service->replaceCredential(
            $fixture['ticket_id'], $this->actor, $sessionId, 'fresh-step-up-token', 'replace-after-admission-'.Uuid::uuid7(),
        ));
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_attendance')
            ->where('ticket_id', $fixture['ticket_id'])->count(), 'Credential replacement must not reset admission history.');
        $this->assertSame(1, DB::connection('community_services')->table('community_services.event_ticket_security_audit')
            ->where('ticket_id', $fixture['ticket_id'])->count());
    }

    public function test_purchaser_credential_routes_are_not_cacheable_across_actors(): void
    {
        $fixture = $this->issueAdmissionTicket();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $headers = ['X-Test-User-Id' => $this->actor, 'X-Test-Actor-Type' => 'CUSTOMER'];

        $list = $this->withHeaders($headers)->getJson('/v1/events/tickets');
        $list->assertOk()->assertHeader('Pragma', 'no-cache');
        $listCache = strtolower((string) $list->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $listCache);
        $this->assertStringContainsString('no-store', $listCache);
        $this->assertStringContainsString($fixture['credential'], $list->getContent());

        $detail = $this->withHeaders($headers)->getJson('/v1/events/tickets/'.$fixture['ticket_id']);
        $detail->assertOk()->assertHeader('Pragma', 'no-cache');
        $detailCache = strtolower((string) $detail->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $detailCache);
        $this->assertStringContainsString('no-store', $detailCache);

        $order = $this->withHeaders($headers)->getJson('/v1/events/orders/'.$fixture['order_id']);
        $order->assertOk()->assertHeader('Pragma', 'no-cache');
        $orderCache = strtolower((string) $order->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $orderCache);
        $this->assertStringContainsString('no-store', $orderCache);
    }

    public function test_offline_reentry_and_transfer_routes_are_not_exposed(): void
    {
        $routes = collect(app('router')->getRoutes())->map(static fn ($route): string => strtolower($route->uri()))
            ->filter(static fn (string $uri): bool => str_starts_with($uri, 'v1/events'));
        $this->assertFalse($routes->contains(static fn (string $uri): bool => str_contains($uri, 'offline')));
        $this->assertFalse($routes->contains(static fn (string $uri): bool => str_contains($uri, 'reentry') || str_contains($uri, 're-entry')));
        $this->assertFalse($routes->contains(static fn (string $uri): bool => str_contains($uri, 'transfer')));
    }

    public function test_unknown_refund_state_and_refunded_funds_deny_admission(): void
    {
        $fixture = $this->issueAdmissionTicket();
        $this->allowPermissions(['events.gate.validate']);
        $this->grantScope($this->actor, $this->organisation, $fixture['gate_id'], 'gate', EventPermissions::GATE_VALIDATE, $fixture['event_id']);
        $payment = DB::connection('payment');
        foreach (['unknown', 'pending', 'confirmed', 'succeeded'] as $status) {
            $intentId = (string) Uuid::uuid7();
            $attemptId = (string) Uuid::uuid7();
            $this->insertAdmissionPaymentFixture($intentId, $attemptId);
            $payment->table('payment.payment_refunds')->insert([
                'id' => (string) Uuid::uuid7(), 'payment_intent_id' => $intentId, 'payment_attempt_id' => $attemptId,
                'amount_minor' => 1, 'status' => $status, 'idempotency_key' => 'p25-admission-refund-'.Uuid::uuid7(),
                'requested_by' => $this->actor, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::connection('community_services')->table('community_services.event_orders')
                ->where('id', $fixture['order_id'])->update(['payment_intent_id' => $intentId]);
            $this->assertSame(['status' => 'denied', 'category' => 'A'], app(EventAdmissionService::class)->validateOnline(
                $fixture['event_id'], $fixture['gate_id'], $this->actor, $fixture['credential'], null,
            ), 'Refund state '.$status.' must block admission.');
        }
        $this->assertSame(0, DB::connection('community_services')->table('community_services.event_attendance')->where('ticket_id', $fixture['ticket_id'])->count());
    }

    public function test_permission_catalogue_is_registered_without_granting_existing_roles(): void
    {
        foreach (array_merge(EventPermissions::all(), EventPermissions::management()) as $slug) {
            $permissionId = DB::connection('identity')->table('identity.permissions')->where('slug', $slug)->value('id');
            $this->assertNotNull($permissionId, $slug.' is registered');
            $this->assertFalse(
                DB::connection('identity')->table('identity.role_permissions as rp')
                    ->join('identity.roles as r', 'r.id', '=', 'rp.role_id')
                    ->where('rp.permission_id', $permissionId)->where('r.is_system_role', true)->exists(),
                $slug.' must not be assigned to a system/global role by this migration',
            );
        }
    }

    public function test_post_publication_edit_preserves_snapshot_pauses_sales_and_invalidates_reviews(): void
    {
        $this->allowTestAuthorizer();
        $this->allowTestWorkflowContracts();
        $input = $this->input();
        $input['sales_start_at'] = '2026-10-01T09:00:00+02:00';
        $draft = app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
        $priceApprover = $this->addMember();
        $eventReviewer = $this->addMember();
        $publisher = $this->addMember();
        DB::connection('community_services')->table('community_services.event_drafts')
            ->where('id', $draft['id'])->update(['currency_code' => 'BWP']);
        $workflow = app(EventWorkflowService::class);
        $workflow->submitForReview($draft['id'], $this->actor, 1);
        $workflow->approvePrices($draft['id'], $priceApprover, 1);
        $workflow->approvePublication($draft['id'], $eventReviewer, 1);
        $workflow->publish($draft['id'], $publisher, 1);
        $workflow->resumeSales($draft['id'], $publisher, 1);

        $edited = $this->input();
        $edited['title'] = 'Proposed new event title';
        $updated = app(EventDraftService::class)->update($draft['id'], $this->actor, 1, $edited);

        $this->assertSame('change_pending', $updated['status']);
        $this->assertSame('paused', $updated['sales_state']);
        $this->assertSame('Test event', $updated['published_snapshot']['title']);
        $this->assertNull($updated['reviewed_version']);
        $this->assertNull($updated['price_approved_version']);
    }

    private function allowTestAuthorizer(): void
    {
        // Explicit test-only substitute; the production binding denies all.
        $this->app->instance(EventDraftAuthorizer::class, new class implements EventDraftAuthorizer
        {
            public function assertCanCreate(string $actorId, string $organisationId): void {}

            public function assertCanRead(string $actorId, string $organisationId, string $eventId): void {}

            public function assertCanUpdate(string $actorId, string $organisationId, string $eventId): void {}
        });
    }

    private function allowInvitationRecipientFixture(string $actorId): void
    {
        $verifier = \Mockery::mock(InvitationRecipientVerifier::class);
        $verifier->shouldReceive('assertActiveCustomerActor')->with($actorId)->andReturnNull();
        $this->app->instance(InvitationRecipientVerifier::class, $verifier);
    }

    /** @return array<string, int> */
    private function financialEffectCounts(): array
    {
        return [
            'payment_intents' => DB::connection('payment')->table('payment.payment_intents')->count(),
            'payment_attempts' => DB::connection('payment')->table('payment.payment_attempts')->count(),
            'payment_refunds' => DB::connection('payment')->table('payment.payment_refunds')->count(),
            'wallet_captures' => DB::connection('wallet')->table('wallet.wallet_capture_records')->count(),
            'ledger_journals' => DB::connection('ledger')->table('ledger.journals')->count(),
            'payout_settlements' => DB::connection('payment')->table('payment.payout_settlement_records')->count(),
        ];
    }

    private function allowComplimentaryApprovalFixture(): void
    {
        $this->app->instance(ComplimentaryAllocationApprovalContract::class, new class implements ComplimentaryAllocationApprovalContract
        {
            public function approvalEvidence(string $organisationId, string $eventId, string $categoryId): ComplimentaryAllocationApprovalEvidence
            {
                $quota = (int) DB::connection('community_services')->table('community_services.event_draft_categories')
                    ->where('id', $categoryId)->where('event_id', $eventId)->value('complimentary_quota');

                return new ComplimentaryAllocationApprovalEvidence(
                    approvalId: 'test-approval-'.$categoryId, version: 1, organisationId: $organisationId,
                    eventId: $eventId, categoryId: $categoryId, approvedQuota: $quota,
                    requestedByActorId: 'test-requester', approvedByActorId: 'test-approver',
                    validFrom: new \DateTimeImmutable('-1 minute'), expiresAt: new \DateTimeImmutable('+1 day'),
                    revokedAt: null, provenance: 'fixture://events-feature-test',
                );
            }
        });
    }

    /** @return array<string, mixed> */
    private function routeDraft(): array
    {
        $this->allowTestAuthorizer();

        return app(EventDraftService::class)->create($this->organisation, $this->actor, $this->input());
    }

    private function useScopedEventAuthorizers(): void
    {
        $this->app->forgetInstance(EventDraftAuthorizer::class);
        $this->app->forgetInstance(EventWorkflowAuthorizer::class);
        $this->app->bind(EventDraftAuthorizer::class, ScopedEventDraftAuthorizer::class);
        $this->app->bind(EventWorkflowAuthorizer::class, ScopedEventWorkflowAuthorizer::class);
    }

    private function requestAs(string $actorId): static
    {
        return $this->withHeaders([
            'X-Test-User-Id' => $actorId,
            'X-Test-Actor-Type' => 'ADMIN',
            'Idempotency-Key' => (string) Uuid::uuid7(),
            'X-Correlation-ID' => (string) Uuid::uuid7(),
        ]);
    }

    private function allowTestWorkflowContracts(): void
    {
        // Test-only authority and readiness; production bindings deny all.
        $this->app->instance(EventWorkflowAuthorizer::class, new class implements EventWorkflowAuthorizer
        {
            public function assertCanSubmit(string $actorId, string $organisationId, string $eventId): void {}

            public function assertCanApprovePublication(string $actorId, string $organisationId, string $eventId): void {}

            public function assertCanApprovePrices(string $actorId, string $organisationId, string $eventId): void {}

            public function assertCanPublish(string $actorId, string $organisationId, string $eventId): void {}

            public function assertCanResumeSales(string $actorId, string $organisationId, string $eventId): void {}
        });
        $this->app->instance(EventPublicationReadiness::class, new class implements EventPublicationReadiness
        {
            public function assertPublishable(array $event, array $categories): void
            {
                if (($event['currency_code'] ?? null) !== 'BWP' || $categories === []) {
                    throw ValidationException::withMessages(['currency_code' => 'Test event must have a supported currency.']);
                }
            }
        });
    }

    /** @param list<string> $permissions */
    private function allowPermissions(array $permissions): void
    {
        $identity = \Mockery::mock(IdentityModuleContract::class);
        $identity->shouldReceive('hasPermission')->andReturnUsing(
            static fn (string $actorId, string $permission): bool => in_array($permission, $permissions, true),
        );
        $this->app->instance(IdentityModuleContract::class, $identity);
    }

    private function grantScope(
        string $actorId,
        string $organisationId,
        string $scopeId,
        string $scopeType,
        string $permission,
        ?string $eventId = null,
    ): string {
        $id = (string) Uuid::uuid7();
        $eventId ??= $scopeType === 'event' ? $scopeId : null;
        DB::connection('community_services')->table('community_services.event_authority_assignments')->insert([
            'id' => $id, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'organisation_id' => $organisationId, 'event_id' => $eventId,
            'gate_id' => $scopeType === 'gate' ? $scopeId : null,
            'actor_id' => $actorId, 'permission_slug' => $permission,
            'granted_by_actor_id' => (string) Uuid::uuid7(), 'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function designateAssignmentAdministrator(string $actorId, string $authorityType): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('community_services')->table('community_services.event_assignment_administrators')->insert([
            'id' => $id, 'organisation_id' => $this->organisation, 'actor_id' => $actorId,
            'authority_type' => $authorityType, 'grant_source' => 'bootstrap',
            'granted_by_actor_id' => (string) Uuid::uuid7(), 'approver_one_actor_id' => (string) Uuid::uuid7(),
            'approver_two_actor_id' => (string) Uuid::uuid7(), 'approval_reference' => 'test-only-'.$id,
            'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array<string, mixed> test-only published state; no production publication path is invoked */
    private function publishedInventoryEvent(int $holdDurationSeconds): array
    {
        $this->allowTestAuthorizer();
        $input = $this->input();
        $input['hold_duration_seconds'] = $holdDurationSeconds;
        $event = app(EventDraftService::class)->create($this->organisation, $this->actor, $input);
        $currency = DB::connection('platform')->table('multi_currency.currencies')->where('is_active', true)
            ->orderBy('code')->first(['code', 'decimal_places', 'updated_at']);
        $this->assertNotNull($currency, 'P25 inventory tests require an active currency fixture.');
        $assignmentId = (string) Uuid::uuid7();
        DB::connection('community_services')->table('community_services.event_currency_assignments')->insert([
            'id' => $assignmentId, 'event_id' => $event['id'], 'organisation_id' => $this->organisation,
            'currency_code' => $currency->code, 'precision_snapshot' => $currency->decimal_places,
            'currency_definition_updated_at' => $currency->updated_at, 'assigned_by_actor_id' => $this->actor,
            'approval_reference' => 'test-only-fixture', 'policy_version' => 'test-only',
            'assigned_at' => now(), 'superseded_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('community_services')->table('community_services.event_drafts')->where('id', $event['id'])
            ->update(['workflow_state' => 'published', 'sales_state' => 'active', 'published_version' => 1,
                'currency_code' => $currency->code, 'currency_precision_snapshot' => $currency->decimal_places,
                'currency_definition_updated_at' => $currency->updated_at, 'currency_assignment_id' => $assignmentId]);

        return app(EventDraftService::class)->get($event['id'], $this->actor);
    }

    private function addMember(): string
    {
        $actorId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert(['id' => $actorId, 'status' => 'active',
            'actor_type' => 'ADMIN', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('identity')->table('identity.organisation_memberships')->insert(['id' => (string) Uuid::uuid7(),
            'organisation_id' => $this->organisation, 'user_id' => $actorId, 'status' => 'active',
            'evidence_reference' => 'test-only', 'requested_by' => $this->actor,
            'reviewed_by' => $this->actor, 'created_at' => now(), 'updated_at' => now()]);

        return $actorId;
    }

    private function assertValidationError(callable $action, string $key): void
    {
        try {
            $action();
            $this->fail('Expected validation error for '.$key);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['title' => 'Test event', 'event_format' => 'physical', 'venue_country_code' => 'BW',
            'organiser_name' => 'Test organiser', 'sponsors' => ['Example sponsor'],
            'capacity' => 10, 'currency_code' => 'BWP', 'timezone' => 'Africa/Gaborone',
            'max_tickets_per_order' => 6,
            'starts_at' => '2027-03-01T09:00:00+02:00', 'ends_at' => '2027-03-01T12:00:00+02:00',
            'sales_start_at' => '2026-10-01T09:00:00+02:00', 'sales_end_at' => '2027-02-28T09:00:00+02:00',
            'cancellation_policy' => 'Supplied cancellation terms', 'transfer_policy' => 'Supplied transfer terms',
            'entry_policy' => 'Supplied entry terms', 'categories' => [
                ['name' => 'A', 'capacity' => 6, 'price_minor' => 1000],
                ['name' => 'B', 'capacity' => 4, 'price_minor' => 2000],
            ]];
    }

    private function assertHttp(int $expected, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected HTTP '.$expected);
        } catch (HttpException $exception) {
            $this->assertSame($expected, $exception->getStatusCode(), $exception->getMessage());
        }
    }
}

// Test-only ticket and Payment fixtures for the P25 online admission contract.
