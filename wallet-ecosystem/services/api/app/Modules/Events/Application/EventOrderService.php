<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Contracts\EventPricingContract;
use App\Modules\Events\Domain\Exceptions\EventPricingUnavailable;
use App\Modules\Events\Domain\ValueObjects\EventPricingBreakdown;
use App\Modules\Events\Domain\ValueObjects\EventPricingLine;
use App\Modules\Events\Domain\ValueObjects\EventPricingRequest;
use App\Modules\Payment\Domain\Contracts\PaymentModuleContract;
use App\Modules\Payment\Domain\PaymentContextIdentifiers;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Domain\ValueObjects\PaymentIntentRequest;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\OleKardTermsAcceptanceQuery;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Owns authoritative event orders, snapshots and inventory-backed issuance. */
final class EventOrderService
{
    public function __construct(
        private readonly EventPricingContract $pricing,
        private readonly EventCurrencyAssignmentService $currency,
        private readonly AdmissionCredentialVault $credentials,
        private readonly PaymentModuleContract $payments,
        private readonly ConfigurationServiceInterface $configuration,
        private readonly OleKardTermsAcceptanceQuery $termsAcceptance,
    ) {}

    private function db(): Connection
    {
        return DB::connection('community_services');
    }

    /** @param list<string> $holdIds @return array<string,mixed> */
    public function createQuote(string $eventId, string $purchaserId, array $holdIds, string $idempotencyKey): array
    {
        if ($holdIds === [] || count($holdIds) > 50 || $idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['quote' => 'A bounded set of holds and an idempotency key are required.']);
        }
        $holdIds = array_values(array_unique($holdIds));
        if (count($holdIds) !== count(array_filter($holdIds, static fn ($id) => is_string($id) && Uuid::isValid($id)))) {
            throw ValidationException::withMessages(['hold_ids' => 'Each inventory hold identifier must be a UUID.']);
        }
        sort($holdIds);
        $requestHash = hash('sha256', json_encode([$eventId, $purchaserId, $holdIds], JSON_THROW_ON_ERROR));

        return $this->termsAcceptance->withPublicationGuard(fn (): array => $this->db()->transaction(function () use ($eventId, $purchaserId, $holdIds, $idempotencyKey, $requestHash): array {
            $event = $this->lockedCurrentEvent($eventId);
            $existing = $this->db()->table('community_services.event_order_quotes')
                ->where('event_id', $eventId)->where('purchaser_actor_id', $purchaserId)->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless(hash_equals((string) $existing->request_hash, $requestHash), 409, 'Quote idempotency key conflicts with its original request.');

                return $this->quoteShape($existing);
            }

            [$lines, $holds, $expiry] = $this->loadPricedHolds($event, $purchaserId, $holdIds);
            $request = new EventPricingRequest($eventId, (string) $event->organisation_id,
                (string) $event->venue_country_code, (string) $event->currency_code,
                (int) $event->currency_precision_snapshot, $lines);
            $price = $this->price($request);
            if ($price->subtotalMinor !== $request->subtotalMinor()) {
                abort(409, 'Pricing subtotal does not match authoritative ticket lines.');
            }
            $this->assertProductionPricingContext($price, $request);
            $ttl = max(1, (int) ($this->configuration->getOrDefault('payment.quote_ttl_seconds', 300) ?? 300));
            $expiry = min($expiry, now()->toImmutable()->modify('+'.$ttl.' seconds'), $price->policyExpiresAt);
            if ($expiry <= now()) {
                abort(409, 'Quote or inventory hold has expired.');
            }
            $id = (string) Uuid::uuid7();
            $lineSnapshot = array_map(static fn (EventPricingLine $line) => $line->toArray(), $lines);
            $confirmationHash = hash('sha256', json_encode([
                'lines' => $lineSnapshot, 'pricing' => $price->confirmationSnapshot(),
            ], JSON_THROW_ON_ERROR));
            $row = [
                'id' => $id, 'event_id' => $eventId, 'organisation_id' => $event->organisation_id,
                'purchaser_actor_id' => $purchaserId, 'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash, 'hold_ids' => json_encode($holdIds, JSON_THROW_ON_ERROR),
                'line_snapshot' => json_encode($lineSnapshot, JSON_THROW_ON_ERROR),
                'pricing_snapshot' => json_encode($price->toArray(), JSON_THROW_ON_ERROR),
                'confirmation_hash' => $confirmationHash, 'status' => 'quoted', 'expires_at' => $expiry,
                'confirmed_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ];
            $this->db()->table('community_services.event_order_quotes')->insert($row);

            return $this->quoteShape((object) $row);
        }, 3));
    }

    /** Creating an order represents explicit confirmation of the displayed quote. */
    public function confirmAndCreateOrder(string $eventId, string $purchaserId, string $quoteId, string $idempotencyKey): array
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded idempotency key is required.']);
        }
        $requestHash = hash('sha256', json_encode([$eventId, $purchaserId, $quoteId], JSON_THROW_ON_ERROR));

        return $this->termsAcceptance->withPublicationGuard(fn (): array => $this->db()->transaction(function () use ($eventId, $purchaserId, $quoteId, $idempotencyKey, $requestHash): array {
            $event = $this->lockedCurrentEvent($eventId);
            $existing = $this->db()->table('community_services.event_orders')->where('event_id', $eventId)
                ->where('purchaser_actor_id', $purchaserId)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless(hash_equals((string) $existing->request_hash, $requestHash), 409, 'Order idempotency key conflicts with its original request.');

                return $this->orderShape($existing);
            }
            $quote = $this->db()->table('community_services.event_order_quotes')->where('id', $quoteId)
                ->where('event_id', $eventId)->where('purchaser_actor_id', $purchaserId)->lockForUpdate()->first();
            abort_if($quote === null, 404, 'Quote was not found.');
            abort_unless($quote->status === 'quoted' && $quote->expires_at > now(), 409, 'Quote is expired or already used.');
            [$lines, $holds] = $this->loadPricedHolds($event, $purchaserId, json_decode((string) $quote->hold_ids, true, 512, JSON_THROW_ON_ERROR));
            $request = new EventPricingRequest($eventId, (string) $event->organisation_id,
                (string) $event->venue_country_code, (string) $event->currency_code,
                (int) $event->currency_precision_snapshot, $lines);
            $price = $this->price($request);
            $this->assertProductionPricingContext($price, $request);
            $lineSnapshot = array_map(static fn (EventPricingLine $line) => $line->toArray(), $lines);
            $confirmationHash = hash('sha256', json_encode(['lines' => $lineSnapshot, 'pricing' => $price->confirmationSnapshot()], JSON_THROW_ON_ERROR));
            abort_unless(hash_equals((string) $quote->confirmation_hash, $confirmationHash), 409,
                'A payable component changed; a new quote and customer confirmation are required.');
            abort_unless($price->policyExpiresAt > now() && $quote->expires_at > now(), 409, 'The confirmed quote expired.');

            // This call locks the same event row and records the immutable currency snapshot before insertion.
            $lockedEvent = $this->currency->lockForFirstObligation($eventId, 'durable_order');
            $id = (string) Uuid::uuid7();
            $order = [
                'id' => $id, 'quote_id' => $quoteId, 'event_id' => $eventId,
                'organisation_id' => $event->organisation_id, 'purchaser_actor_id' => $purchaserId,
                'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash,
                'line_snapshot' => $quote->line_snapshot, 'pricing_snapshot' => $quote->pricing_snapshot,
                'confirmation_hash' => $quote->confirmation_hash,
                'subtotal_minor' => $price->subtotalMinor, 'fees_minor' => $price->feesMinor,
                'taxes_minor' => $price->taxesMinor, 'total_minor' => $price->totalMinor,
                'currency_code' => $price->currencyCode, 'currency_precision' => $price->currencyPrecision,
                'seller_merchant_reference' => $price->sellerMerchantReference,
                'fee_policy_version' => $price->feePolicyVersion, 'tax_policy_version' => $price->taxPolicyVersion,
                'order_state' => 'awaiting_payment', 'payment_state' => 'uninitiated',
                'accounting_state' => 'unverified', 'fulfilment_state' => 'pending',
                'payment_intent_id' => null, 'currency_locked_at' => $lockedEvent->currency_locked_at,
                'created_at' => now(), 'updated_at' => now(),
            ];
            $this->db()->table('community_services.event_orders')->insert($order);
            foreach ($holds as $hold) {
                $this->db()->table('community_services.event_order_lines')->insert([
                    'id' => (string) Uuid::uuid7(), 'order_id' => $id, 'hold_id' => $hold->id,
                    'category_id' => $hold->category_id, 'category_name' => $hold->category_name,
                    'quantity' => (int) $hold->quantity,
                    'unit_price_minor' => (int) $this->categoryPrice($hold->category_id),
                    'line_subtotal_minor' => (int) $this->categoryPrice($hold->category_id) * (int) $hold->quantity,
                    'created_at' => now(),
                ]);
                $this->db()->table('community_services.event_inventory_holds')->where('id', $hold->id)->update(['order_id' => $id, 'updated_at' => now()]);
            }
            $this->db()->table('community_services.event_draft_audit')->insert([
                'id' => (string) Uuid::uuid7(), 'event_id' => $eventId, 'actor_id' => $purchaserId,
                'action' => 'order_created', 'version' => (int) $event->version, 'created_at' => now(),
            ]);
            $this->db()->table('community_services.event_order_quotes')->where('id', $quoteId)->update([
                'status' => 'confirmed', 'confirmed_at' => now(), 'updated_at' => now(),
            ]);

            return $this->orderShape((object) $order);
        }, 3));
    }

    /** Payment is created only for a durable, complete order; retries use one stable key. */
    public function createPaymentIntent(string $orderId, string $purchaserId, string $correlationId): array
    {
        return $this->termsAcceptance->withPublicationGuard(fn (): array => $this->db()->transaction(function () use ($orderId, $purchaserId, $correlationId): array {
            $orderReference = $this->db()->table('community_services.event_orders')->where('id', $orderId)->first(['event_id']);
            abort_if($orderReference === null, 404, 'Order was not found.');
            $event = $this->lockedCurrentEvent((string) $orderReference->event_id);
            $order = $this->db()->table('community_services.event_orders')->where('id', $orderId)->lockForUpdate()->first();
            abort_if($order === null || $order->purchaser_actor_id !== $purchaserId, 404, 'Order was not found.');
            abort_unless($event->organisation_id === $order->organisation_id
                && ($event->event_format ?? null) === 'physical' && $event->venue_country_code !== null, 409,
                'An approved physical venue country is required for checkout eligibility.');
            abort_unless($order->order_state === 'awaiting_payment' && $order->currency_code !== '', 409, 'Order is not payable.');
            abort_unless($this->holdsCurrentForOrder($orderId), 409, 'Order inventory hold expired; payment initiation is blocked.');
            $this->assertOrderTermsStillCurrent($order);
            $options = $this->payments->getAvailableOptions(new OptionsQuery(
                purposeType: PaymentContextIdentifiers::PURPOSE_PAID_EVENT_TICKET,
                purposeReference: $orderId, currencyCode: (string) $order->currency_code,
                amountMinor: (int) $order->total_minor, organisationId: (string) $order->organisation_id,
                actorId: $purchaserId, countryCode: (string) $event->venue_country_code,
                moduleKey: PaymentContextIdentifiers::MODULE_EVENTS,
            ));
            abort_if($options === [], 409, 'No payment method is eligible for this customer and event order.');
            $result = $this->payments->createPaymentIntent(new PaymentIntentRequest(
                purposeType: 'paid_event_ticket', purposeReference: $orderId,
                organisationId: (string) $order->organisation_id, payerActorId: $purchaserId,
                currencyCode: (string) $order->currency_code, idempotencyKey: 'event-order:'.$orderId,
                correlationId: $correlationId, metadata: ['event_order_id' => $orderId],
            ));
            abort_unless((int) $result['amount_minor'] === (int) $order->total_minor
                && $result['currency_code'] === $order->currency_code, 409, 'Payment obligation does not match the immutable order.');
            if ($order->payment_intent_id !== null && $order->payment_intent_id !== $result['payment_intent_id']) {
                throw new \DomainException('Payment intent changed for an immutable event order.');
            }
            $this->db()->table('community_services.event_orders')->where('id', $orderId)->update([
                'payment_intent_id' => $result['payment_intent_id'], 'payment_state' => 'intent_created', 'updated_at' => now(),
            ]);

            return $result;
        }, 3));
    }

    /** @return array<string,mixed> */
    public function orderForPurchaser(string $orderId, string $purchaserId): array
    {
        $order = $this->db()->table('community_services.event_orders')->where('id', $orderId)
            ->where('purchaser_actor_id', $purchaserId)->first();
        abort_if($order === null, 404, 'Order was not found.');
        $shape = $this->orderShape($order);
        if ($order->fulfilment_state === 'issued') {
            $shape['tickets'] = $this->db()->table('community_services.event_tickets')->where('order_id', $orderId)->where('status', 'valid')
                ->where('owner_actor_id', $purchaserId)->orderBy('created_at')->get()->map(fn ($ticket) => [
                    'ticket_id' => $ticket->id, 'category' => $ticket->category_name,
                    'credential' => $this->credentials->recoverForDisplay((string) $ticket->credential_encrypted),
                    'status' => $ticket->status,
                ])->all();
        }

        return $shape;
    }

    /** @return array{0:list<EventPricingLine>,1:list<object>,2:\DateTimeImmutable} */
    private function loadPricedHolds(object $event, string $purchaserId, array $holdIds): array
    {
        $lines = [];
        $holds = [];
        $expiry = null;
        foreach ($holdIds as $holdId) {
            $hold = $this->db()->table('community_services.event_inventory_holds')->where('id', $holdId)->lockForUpdate()->first();
            abort_if($hold === null || $hold->event_id !== $event->id || $hold->organisation_id !== $event->organisation_id
                || $hold->customer_actor_id !== $purchaserId || $hold->order_id !== null, 404, 'Inventory hold is unavailable.');
            abort_unless($hold->status === 'held' && $hold->expires_at > now(), 409, 'Inventory hold is expired or inactive.');
            $category = $this->db()->table('community_services.event_draft_categories')->where('id', $hold->category_id)
                ->where('event_id', $event->id)->first();
            abort_if($category === null || (int) $category->price_minor < 1, 409, 'Paid category price is unavailable.');
            $lines[] = new EventPricingLine((string) $category->id, (string) $category->name,
                (int) $hold->quantity, (int) $category->price_minor);
            $holds[] = $hold;
            $when = new \DateTimeImmutable((string) $hold->expires_at);
            $expiry = $expiry === null || $when < $expiry ? $when : $expiry;
        }
        if ($expiry === null) {
            throw new \DomainException('An event order quote requires at least one unexpired inventory hold.');
        }

        return [$lines, $holds, $expiry];
    }

    private function price(EventPricingRequest $request): EventPricingBreakdown
    {
        try {
            $price = $this->pricing->quote($request);
        } catch (EventPricingUnavailable $exception) {
            throw $exception;
        }
        $price->assertEnvironmentEligible();
        abort_unless($price->currencyCode === $request->currencyCode
            && $price->currencyPrecision === $request->currencyPrecision, 409, 'Pricing currency snapshot does not match the event assignment.');
        abort_unless($price->policyExpiresAt > now(), 409, 'Pricing policy evidence has expired.');
        if (! $price->testOnly) {
            $terms = $price->oleKardTermsAcceptanceSnapshot;
            abort_unless($terms !== null
                && ($terms['organisation_id'] ?? null) === $request->organisationId
                && ($terms['event_id'] ?? null) === $request->eventId, 409,
                'Current OleKard terms acceptance must match the authoritative organisation and event.');
            try {
                $termsStart = new \DateTimeImmutable((string) $terms['effective_from']);
                $termsEnd = new \DateTimeImmutable((string) $terms['effective_until']);
                $acceptedAt = new \DateTimeImmutable((string) $terms['accepted_at']);
            } catch (\Throwable) {
                abort(409, 'OleKard terms effective dates and acceptance time are invalid.');
            }
            abort_unless($termsStart <= now()->toImmutable() && $termsEnd > now()->toImmutable()
                && $acceptedAt <= now()->toImmutable(), 409,
                'Current OleKard terms acceptance is outside its effective period.');
            $authoritative = $this->termsAcceptance->currentAcceptanceSnapshot($request->organisationId, $request->eventId);
            abort_unless($authoritative !== null, 409, 'No current, authoritative organiser acceptance of OleKard terms is available.');
            foreach (['acceptance_id', 'version_id', 'accepted_version_id', 'version', 'approval_reference',
                'approved_by_actor_id', 'approved_at', 'document_hash', 'accepted_document_hash',
                'acceptance_provenance', 'organisation_id', 'event_id', 'accepted_by_actor_id',
                'accepted_at', 'effective_from', 'effective_until'] as $field) {
                abort_unless(isset($terms[$field], $authoritative[$field])
                    && hash_equals((string) $authoritative[$field], (string) $terms[$field]), 409,
                    'Pricing terms snapshot does not match the authoritative current acceptance.');
            }
        }

        return $price;
    }

    private function assertProductionPricingContext(EventPricingBreakdown $price, EventPricingRequest $request): void
    {
        if ($price->testOnly) {
            return;
        }
        $schedule = $price->organiserFeeScheduleSnapshot;
        $tax = $price->taxPolicySnapshot;
        abort_unless($schedule !== null && $tax !== null, 409, 'Approved fee and tax policy snapshots are required.');

        $scope = $schedule['scope'] ?? [];
        $scopeMatches = ($scope['type'] ?? null) === 'organisation'
            ? ($scope['id'] ?? null) === $request->organisationId
            : (($scope['type'] ?? null) === 'event' && ($scope['id'] ?? null) === $request->eventId);
        abort_unless($scopeMatches && ($schedule['currency_code'] ?? null) === $request->currencyCode, 409,
            'Fee schedule scope or currency does not match the authoritative event.');

        try {
            $feeStart = new \DateTimeImmutable((string) $schedule['effective_from']);
            $feeEnd = $schedule['effective_until'] === null ? null : new \DateTimeImmutable((string) $schedule['effective_until']);
            $taxStart = new \DateTimeImmutable((string) $tax['effective_from']);
            $taxEnd = $tax['effective_until'] === null ? null : new \DateTimeImmutable((string) $tax['effective_until']);
        } catch (\Throwable $exception) {
            abort(409, 'Pricing policy effective dates are invalid.');
        }
        $now = now()->toImmutable();
        abort_unless($feeStart <= $now && ($feeEnd === null || $feeEnd > $now)
            && $taxStart <= $now && ($taxEnd === null || $taxEnd > $now), 409,
            'Pricing policy is outside its approved effective period.');
    }

    private function assertOrderTermsStillCurrent(object $order): void
    {
        $pricing = json_decode((string) $order->pricing_snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (($pricing['test_only'] ?? false) === true) {
            abort_unless(app()->environment('testing'), 409, 'Test-only pricing cannot initiate a payment outside tests.');

            return;
        }
        $terms = $pricing['olekard_terms_acceptance_snapshot'] ?? null;
        $current = $this->termsAcceptance->currentAcceptanceSnapshot((string) $order->organisation_id, (string) $order->event_id);
        abort_unless(is_array($terms) && $current !== null
            && ($terms['version_id'] ?? null) === ($current['version_id'] ?? null)
            && isset($terms['document_hash'], $current['document_hash'])
            && hash_equals((string) $current['document_hash'], (string) $terms['document_hash']), 409,
            'Current OleKard terms acceptance changed; no new payment may be initiated for this order.');
    }

    private function lockedCurrentEvent(string $eventId): object
    {
        $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
        abort_if($event === null, 404, 'Event was not found.');
        abort_unless($event->workflow_state === 'published' && $event->sales_state === 'active'
            && ($event->lifecycle_state ?? 'active') === 'active'
            && (int) $event->published_version === (int) $event->version, 409, 'Event is not in its current approved sales state.');
        abort_unless(($event->event_format ?? 'physical') === 'physical' && preg_match('/^[A-Z]{2}$/D', (string) $event->venue_country_code) === 1,
            409, 'Paid event orders require one approved physical venue country.');
        abort_unless($event->currency_code !== null && $event->currency_precision_snapshot !== null, 409, 'Finance currency assignment is required.');

        return $event;
    }

    private function holdsCurrentForOrder(string $orderId): bool
    {
        return $this->db()->table('community_services.event_order_lines as l')
            ->join('community_services.event_inventory_holds as h', 'h.id', '=', 'l.hold_id')
            ->where('l.order_id', $orderId)->where('h.status', 'held')->where('h.expires_at', '>', now())->count()
            === $this->db()->table('community_services.event_order_lines')->where('order_id', $orderId)->count();
    }

    private function categoryPrice(string $categoryId): int
    {
        return (int) $this->db()->table('community_services.event_draft_categories')->where('id', $categoryId)->value('price_minor');
    }

    private function quoteShape(object $quote): array
    {
        $pricing = json_decode((string) $quote->pricing_snapshot, true, 512, JSON_THROW_ON_ERROR);

        return ['quote_id' => $quote->id, 'event_id' => $quote->event_id,
            'expires_at' => (string) $quote->expires_at, 'status' => $quote->status,
            'lines' => json_decode((string) $quote->line_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'pricing' => $this->customerPricingShape($pricing)];
    }

    private function orderShape(object $order): array
    {
        $pricing = json_decode((string) $order->pricing_snapshot, true, 512, JSON_THROW_ON_ERROR);

        return ['order_id' => $order->id, 'event_id' => $order->event_id,
            'organisation_id' => $order->organisation_id, 'purchaser_actor_id' => $order->purchaser_actor_id,
            'line_snapshot' => json_decode((string) $order->line_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'pricing' => $this->customerPricingShape($pricing),
            'total_minor' => (int) $order->total_minor, 'currency_code' => $order->currency_code,
            'order_state' => $order->order_state, 'payment_state' => $order->payment_state,
            'accounting_state' => $order->accounting_state, 'fulfilment_state' => $order->fulfilment_state,
            'payment_intent_id' => $order->payment_intent_id];
    }

    /** @param array<string,mixed> $pricing @return array<string,mixed> */
    private function customerPricingShape(array $pricing): array
    {
        return array_intersect_key($pricing, array_flip([
            'subtotal_minor', 'fees_minor', 'taxes_minor', 'total_minor', 'currency_code',
            'currency_precision', 'fee_components', 'tax_components',
        ]));
    }
}
