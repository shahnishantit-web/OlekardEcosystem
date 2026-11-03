<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Contracts\EventsPaymentServiceActorProvider;
use App\Modules\Payment\Domain\Contracts\PaymentInternalConfirmationContract;
use App\Modules\Platform\Domain\Contracts\OutboxVersionCheckerInterface;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Durable inbox + per-entitlement issuance; Payment acknowledgement follows the Events commit. */
final class EventPaymentConfirmationConsumer
{
    public function __construct(
        private readonly PaymentInternalConfirmationContract $payments,
        private readonly EventsPaymentServiceActorProvider $serviceActor,
        private readonly AdmissionCredentialVault $credentials,
        private readonly OutboxVersionCheckerInterface $versionChecker,
    ) {}

    /** @param array<string,mixed> $payload */
    public function handle(array $payload): void
    {
        foreach (['payment_intent_id', 'purpose_type', 'purpose_reference', 'organisation_id', 'payer_actor_id'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key]) || $payload[$key] === '') {
                throw new \InvalidArgumentException('Payment confirmation payload is incomplete.');
            }
        }
        if ($payload['purpose_type'] !== 'paid_event_ticket' || ! Uuid::isValid($payload['payment_intent_id'])) {
            return;
        }
        $sourceEventId = is_string($payload['eventId'] ?? null) ? $payload['eventId'] : null;
        if ($this->versionChecker->checkAndDeadLetterIfUnsupported($sourceEventId, ['1'])) {
            return;
        }
        $db = DB::connection('community_services');
        $intentId = $payload['payment_intent_id'];
        $orderId = $payload['purpose_reference'];
        $eventId = $sourceEventId;
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $actor = $this->serviceActor->actorFor($payload['organisation_id']);
        $evidence = $this->payments->confirmationEvidence($actor, $intentId, $payload['organisation_id'], 'paid_event_ticket', $orderId);
        $expected = $db->table('community_services.event_orders')->where('id', $orderId)->first();

        if ($evidence === null) {
            $this->persistInbox($payload, $payloadHash, $eventId, $expected, null, 'awaiting_evidence', 'Authoritative P08 evidence is not currently available.');
            if ($expected !== null) {
                $db->table('community_services.event_orders')->where('id', $expected->id)
                    ->where('fulfilment_state', '!=', 'issued')->update(['payment_state' => 'unknown', 'updated_at' => now()]);
            }
            throw new \RuntimeException('Events confirmation waits for authoritative Payment/Ledger evidence.');
        }

        $matches = $expected !== null
            && $evidence->paymentIntentId === $intentId
            && $evidence->organisationId === $expected->organisation_id
            && $evidence->payerActorId === $expected->purchaser_actor_id
            && $evidence->payerActorId === $payload['payer_actor_id']
            && $evidence->purposeReference === $expected->id
            && $evidence->purposeType === 'paid_event_ticket'
            && $evidence->capturedAmountMinor === (int) $expected->total_minor
            && $evidence->capturedCurrencyCode === $expected->currency_code
            && $evidence->accountingStatus === 'ledger_posted'
            && $evidence->ledgerJournalId !== ''
            && (int) ($payload['amount_minor'] ?? 0) === (int) $expected->total_minor
            && ($payload['currency_code'] ?? null) === $expected->currency_code
            && (! array_key_exists('captured_amount_minor', $payload) || $payload['captured_amount_minor'] === null
                || (int) $payload['captured_amount_minor'] === $evidence->capturedAmountMinor)
            && (! array_key_exists('captured_currency_code', $payload) || $payload['captured_currency_code'] === null
                || $payload['captured_currency_code'] === $evidence->capturedCurrencyCode)
            && (! array_key_exists('ledger_journal_id', $payload) || $payload['ledger_journal_id'] === null
                || $payload['ledger_journal_id'] === $evidence->ledgerJournalId);

        if (! $matches) {
            $this->persistInbox($payload, $payloadHash, $eventId, $expected, $evidence, 'blocked_reconciliation', 'Confirmation does not match the authoritative order snapshot.');
            if ($expected !== null && $evidence !== null) {
                $db->table('community_services.event_orders')->where('id', $expected->id)
                    ->where('fulfilment_state', '!=', 'issued')->update([
                        'order_state' => 'blocked_reconciliation', 'payment_state' => 'confirmed',
                        'accounting_state' => $evidence->accountingStatus === 'ledger_posted' ? 'verified' : 'unverified',
                        'fulfilment_state' => 'blocked_reconciliation', 'updated_at' => now(),
                    ]);
            }

            return;
        }

        if ($expected === null) {
            throw new \LogicException('Order and authoritative evidence must exist after confirmation matching.');
        }

        $result = ['ack' => false, 'order' => (string) $expected->id];
        $db->transaction(function () use ($db, $expected, $payload, $payloadHash, $eventId, $evidence, &$result): void {
            $order = $db->table('community_services.event_orders')->where('id', $expected->id)->lockForUpdate()->first();
            if ($order === null) {
                throw new \RuntimeException('Confirmed event order disappeared before issuance.');
            }
            $inbox = $db->table('community_services.event_payment_inbox')->where('payment_intent_id', $evidence->paymentIntentId)->lockForUpdate()->first();
            if ($inbox !== null && $inbox->payload_hash !== $payloadHash) {
                $db->table('community_services.event_payment_inbox')->where('id', $inbox->id)->update([
                    'state' => 'blocked_reconciliation', 'block_reason' => 'Conflicting confirmation payload for the same payment intent.', 'updated_at' => now(),
                ]);
                if ($order->fulfilment_state !== 'issued') {
                    $db->table('community_services.event_orders')->where('id', $order->id)->update(['order_state' => 'blocked_reconciliation', 'payment_state' => 'confirmed', 'accounting_state' => 'verified', 'updated_at' => now()]);
                }

                $result = ['ack' => false, 'order' => (string) $order->id];

                return;
            }
            if ($inbox === null) {
                $inboxId = (string) Uuid::uuid7();
                $db->table('community_services.event_payment_inbox')->insert([
                    'id' => $inboxId, 'source_event_id' => $eventId, 'payment_intent_id' => $evidence->paymentIntentId,
                    'order_id' => $order->id, 'organisation_id' => $order->organisation_id,
                    'payload_hash' => $payloadHash, 'financial_facts' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'verified_evidence' => json_encode($this->evidenceShape($evidence), JSON_THROW_ON_ERROR),
                    'state' => 'received', 'ack_state' => 'pending', 'block_reason' => null,
                    'received_at' => now(), 'processed_at' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif ($inbox->state === 'processed' && $order->fulfilment_state === 'issued') {
                $result = ['ack' => $inbox->ack_state !== 'complete', 'order' => (string) $order->id];

                return;
            }

            if ($order->fulfilment_state === 'issued' && $order->order_state === 'issued') {
                $result = ['ack' => true, 'order' => (string) $order->id];

                return;
            }
            $event = $db->table('community_services.event_drafts')->where('id', $order->event_id)->lockForUpdate()->first();
            $lines = $db->table('community_services.event_order_lines')->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $eligible = $event !== null && $event->workflow_state === 'published' && $event->sales_state === 'active'
                && (int) $event->published_version === (int) $event->version
                && $order->order_state === 'awaiting_payment';
            foreach ($lines as $line) {
                $hold = $db->table('community_services.event_inventory_holds')->where('id', $line->hold_id)->lockForUpdate()->first();
                $eligible = $eligible && $hold !== null && $hold->status === 'held' && $hold->order_id === $order->id && $hold->expires_at > now();
            }
            if (! $eligible || $lines->isEmpty()) {
                $db->table('community_services.event_orders')->where('id', $order->id)->update([
                    'order_state' => 'blocked_reconciliation', 'payment_state' => 'confirmed', 'accounting_state' => 'verified',
                    'fulfilment_state' => 'blocked_reconciliation', 'updated_at' => now(),
                ]);
                $db->table('community_services.event_payment_inbox')->where('payment_intent_id', $evidence->paymentIntentId)->update([
                    'state' => 'blocked_reconciliation', 'verified_evidence' => json_encode($this->evidenceShape($evidence), JSON_THROW_ON_ERROR),
                    'block_reason' => 'Verified capture arrived after order eligibility or inventory hold ended; financial disposition requires review.', 'updated_at' => now(),
                ]);

                $result = ['ack' => false, 'order' => (string) $order->id];

                return;
            }
            if ($event === null) {
                throw new \LogicException('An eligible event must be present during issuance.');
            }

            foreach ($lines as $line) {
                $hold = $db->table('community_services.event_inventory_holds')->where('id', $line->hold_id)->first();
                if ($hold === null) {
                    throw new \LogicException('Inventory hold disappeared during locked ticket issuance.');
                }
                $db->table('community_services.event_draft_categories')->where('id', $line->category_id)->lockForUpdate()->increment('issued_quantity', (int) $line->quantity);
                for ($n = 1; $n <= (int) $line->quantity; $n++) {
                    $secret = $this->credentials->create();
                    $db->table('community_services.event_tickets')->insert([
                        'id' => (string) Uuid::uuid7(), 'order_id' => $order->id, 'order_line_id' => $line->id,
                        'entitlement_number' => $n, 'event_id' => $order->event_id, 'organisation_id' => $order->organisation_id,
                        'owner_actor_id' => $order->purchaser_actor_id, 'category_id' => $line->category_id,
                        'category_name' => $line->category_name, 'credential_hash' => $secret['hash'],
                        'credential_encrypted' => $secret['encrypted'], 'status' => 'valid',
                        'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $db->table('community_services.event_inventory_holds')->where('id', $hold->id)->update(['status' => 'consumed', 'updated_at' => now()]);
            }
            $db->table('community_services.event_orders')->where('id', $order->id)->update([
                'order_state' => 'issued', 'payment_state' => 'confirmed', 'accounting_state' => 'verified',
                'fulfilment_state' => 'issued', 'updated_at' => now(),
            ]);
            $db->table('community_services.event_payment_inbox')->where('payment_intent_id', $evidence->paymentIntentId)->update([
                'state' => 'processed', 'verified_evidence' => json_encode($this->evidenceShape($evidence), JSON_THROW_ON_ERROR),
                'processed_at' => now(), 'updated_at' => now(),
            ]);
            $db->table('community_services.event_draft_audit')->insert([
                'id' => (string) Uuid::uuid7(), 'event_id' => $order->event_id, 'actor_id' => $order->purchaser_actor_id,
                'action' => 'ticket_entitlements_issued', 'version' => (int) $event->version, 'created_at' => now(),
            ]);

            $result = ['ack' => true, 'order' => (string) $order->id];
        },
            3,
        );

        if (! $result['ack']) {
            return;
        }
        $this->payments->acknowledgeFulfilment(
            $actor, $evidence->paymentIntentId, $evidence->organisationId, 'paid_event_ticket', $evidence->purposeReference,
            'event-order:'.$result['order'], 'event-order-fulfilment:'.$result['order'],
        );
        $db->table('community_services.event_payment_inbox')->where('payment_intent_id', $evidence->paymentIntentId)->update([
            'ack_state' => 'complete', 'updated_at' => now(),
        ]);
    }

    private function persistInbox(array $payload, string $hash, ?string $eventId, ?object $order, mixed $evidence, string $state, string $reason): void
    {
        $db = DB::connection('community_services');
        $id = $payload['payment_intent_id'];
        $existing = $db->table('community_services.event_payment_inbox')->where('payment_intent_id', $id)->first();
        $row = ['source_event_id' => $eventId, 'order_id' => $order?->id, 'organisation_id' => $payload['organisation_id'],
            'payload_hash' => $hash, 'financial_facts' => json_encode($payload, JSON_THROW_ON_ERROR),
            'verified_evidence' => $evidence === null ? null : json_encode($this->evidenceShape($evidence), JSON_THROW_ON_ERROR),
            'state' => $state, 'block_reason' => $reason, 'updated_at' => now()];
        if ($existing === null) {
            $db->table('community_services.event_payment_inbox')->insert($row + [
                'id' => (string) Uuid::uuid7(), 'payment_intent_id' => $id, 'ack_state' => 'pending',
                'received_at' => now(), 'processed_at' => null, 'created_at' => now(),
            ]);
        } elseif ($existing->payload_hash === $hash) {
            $db->table('community_services.event_payment_inbox')->where('id', $existing->id)->update($row);
        } else {
            $db->table('community_services.event_payment_inbox_conflicts')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(), 'payment_intent_id' => $id, 'payload_hash' => $hash,
                'financial_facts' => json_encode($payload, JSON_THROW_ON_ERROR),
                'verified_evidence' => $evidence === null ? null : json_encode($this->evidenceShape($evidence), JSON_THROW_ON_ERROR),
                'received_at' => now(),
            ]);
            $db->table('community_services.event_payment_inbox')->where('id', $existing->id)->update([
                'state' => 'blocked_reconciliation', 'block_reason' => 'Conflicting payment confirmation payload was received.', 'updated_at' => now(),
            ]);
            if ($order !== null) {
                $db->table('community_services.event_orders')->where('id', $order->id)->where('fulfilment_state', '!=', 'issued')->update([
                    'order_state' => 'blocked_reconciliation', 'fulfilment_state' => 'blocked_reconciliation', 'updated_at' => now(),
                ]);
            }
        }
    }

    private function evidenceShape(object $evidence): array
    {
        return ['payment_intent_id' => $evidence->paymentIntentId, 'payment_attempt_id' => $evidence->paymentAttemptId,
            'organisation_id' => $evidence->organisationId, 'payer_actor_id' => $evidence->payerActorId,
            'purpose_type' => $evidence->purposeType, 'purpose_reference' => $evidence->purposeReference,
            'captured_amount_minor' => $evidence->capturedAmountMinor, 'captured_currency_code' => $evidence->capturedCurrencyCode,
            'ledger_journal_id' => $evidence->ledgerJournalId, 'accounting_status' => $evidence->accountingStatus];
    }
}
