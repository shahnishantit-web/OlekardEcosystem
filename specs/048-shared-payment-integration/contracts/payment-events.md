# Payment Integration Events Contract (E48)

Events are published via `OutboxEventPublisher` after confirmed DB state commits. Consumers must process events idempotently.

---

## Event Naming Convention

Following the repository standard (dot-separated, lowercase, past tense, no module prefix):
- `payment.confirmed`
- `payment.failed`
- `payment.reversed`
- `refund.confirmed`

---

## Event Payloads

See [module-contract.md](module-contract.md) for full payload shapes.

---

## Consumer Idempotency Requirement

All subscribers MUST implement idempotent event handling. The recommended pattern:

```php
// In a horizontal module's event listener:
public function handle(array $event): void
{
    $alreadyProcessed = $this->fulfilmentRepository->existsByPaymentIntentId(
        $event['payment_intent_id']
    );
    if ($alreadyProcessed) {
        return; // idempotent no-op
    }
    // ... execute fulfilment
}
```

---

## Dead-Letter Handling

If `PaymentCallbackProcessingJob` cannot process a callback after max retries:
1. Callback record status → `dead_lettered`
2. Alert raised to operations team
3. `ReconciliationException` created with type `missing_callback`
4. Operations runbook: `docs/runbooks/payment-callback-dead-letter.md`

---

## Outbox Publishing Guarantee

Events are published using the Platform `OutboxEventPublisher` pattern:
1. Event record inserted into outbox table within the same DB transaction as the state change
2. Background worker polls outbox and publishes to event bus
3. Published events are marked `published` in outbox
4. If event bus is unavailable, events remain in outbox and are retried

This guarantees at-least-once delivery. Consumers MUST be idempotent.
