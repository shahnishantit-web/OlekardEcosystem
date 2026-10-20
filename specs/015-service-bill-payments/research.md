# Research — E15 Service & Bill Payments

## 1. Provider Adapter Pattern

**Decision**: Mirror the `FundingProviderAdapter` interface from E08 with a `ServiceProviderAdapter` interface.

**Rationale**: E08 established the adapter pattern for external provider integration — a thin interface with `initiate`, `verifyCallback`, and status-query methods, backed by a `MockFundingProviderAdapter` for dev/test. E15's provider interaction differs (validate reference → submit payment → handle callback/re-query) but the structural pattern is identical. Reusing the pattern keeps modules consistent and extraction-ready.

**Alternatives considered**: No alternative; the E08 pattern is established platform convention.

**ServiceProviderAdapter methods**:
```
validateReference(array $params): array
  params: service_id, provider_code, customer_reference, correlation_id
  returns: valid (bool), account_name (string|null), error_code (string|null)

submitPayment(array $params): array
  params: transaction_id, service_id, provider_code, customer_reference, amount, currency_code, transaction_reference, correlation_id
  returns: provider_reference (string|null), status ('success'|'failure'|'unknown')

queryStatus(array $params): array
  params: transaction_id, provider_code, provider_reference, correlation_id
  returns: status ('success'|'failure'|'pending')

verifyCallback(array $payload, string $signature, string $provider): array
  returns: valid (bool), provider_reference (string|null), status ('success'|'failure'|'unknown')
```

---

## 2. ServiceTransaction State Machine

**Decision**: Six statuses: `initiated` → `pending_provider` → `completed` | `failed` | `timeout_pending_review` | `abandoned`.

**Rationale**: Capturing `initiated` before any money moves is the correct approach (confirmed in clarification Q2). It provides a recovery anchor, enables idempotency key enforcement at record creation time, and gives the customer visibility into in-progress attempts. The `abandoned` terminal state covers stale `initiated` records without a wallet effect. `timeout_pending_review` is a non-terminal holding state for admin/re-query resolution.

**State transitions**:

```
initiated
  → pending_provider     (wallet debit succeeded + provider submitted)
  → abandoned            (stale cleanup job; no wallet effect)

pending_provider
  → completed            (provider success callback or forced by admin)
  → failed               (provider failure callback, submission error, or forced by admin)
  → timeout_pending_review (no provider response within timeout window)

timeout_pending_review
  → completed            (re-query job resolves success, or admin forces success)
  → failed               (re-query job resolves failure, or admin forces failure)

completed, failed, abandoned — terminal; no further transitions
```

**Alternatives considered**: Creating the record atomically with the debit (Option B from clarification). Rejected because a system crash between record creation and debit would leave an orphaned debit with no transaction reference; the `initiated` state prevents this.

---

## 3. Fee Calculation Model

**Decision**: Fees are `flat` (fixed minor-unit amount) or `percentage` (basis points, i.e., 1% = 100 bps). Stored on the `Service` entity. Fee is recalculated at payment initiation time; if changed since the last quote, return `FeeChangedException` (`FEE_CHANGED` error code).

**Rationale**: Flat fees cover the majority of service payment use cases (e.g., BWP 2.00 per electricity payment). Percentage is needed for telecoms top-up where provider fees are proportional. Basis-point storage avoids floating-point arithmetic errors. The `FeeChangedException` pattern already exists in E08 (`Funding\Domain\Exceptions\FeeChangedException`).

**Alternatives considered**: Dynamic fee from provider at validation time. Rejected because provider selection is TBD and the complexity of provider-negotiated fees is out of scope.

---

## 4. Background Job Pattern for Timeout Re-Query

**Decision**: Two scheduled jobs — `ReQueryTimeoutTransactionJob` (every 5 min) and `AbandonStaleTransactionsJob` (every 15 min). Both dispatched by Laravel Scheduler via `Console/AbandonStaleServiceTransactionsCommand.php`.

**Rationale**: E08 has `Console/ExpireStaleTransactionsCommand.php` as the established pattern for background cleanup. The re-query job follows the same approach, processing `timeout_pending_review` transactions in batches, calling `ServiceProviderAdapter::queryStatus()`, and dispatching outcome handling if resolved.

**Configuration** (seeded as Platform Configuration stubs, not hard-coded):
- `service.payment.requery_interval_seconds` = 300
- `service.payment.requery_max_attempts` = 12 (1 hour total)
- `service.payment.stale_initiated_timeout_minutes` = 30

**Alternatives considered**: Queue-based retry with exponential backoff. Rejected for simplicity; configurable interval is sufficient for the volume anticipated.

---

## 5. Callback Idempotency

**Decision**: `service_provider_callbacks` table with unique constraint on `(provider_code, provider_reference)`. Duplicate callbacks return `200 OK` without re-processing, consistent with E08's `DuplicateCallbackException` pattern.

**Rationale**: Provider systems sometimes send duplicate callbacks (network retries, at-least-once delivery). The unique constraint guards against re-processing at the database level regardless of race conditions.

---

## 6. Ledger Account Registration

**Decision**: Register `service_payable:{currencyCode}` as a liability ledger account during the first payment for that currency code. Follow the same `LedgerModuleContract::registerAccount()` pattern used in E08 and E13.

**Rationale**: The ledger module requires accounts to exist before journals can be posted against them. Lazy registration (first payment) avoids migration-time coupling to a TBD set of currencies.

---

## 7. Step-Up Authentication Integration

**Decision**: Check the platform-configured step-up threshold (`auth.step_up_threshold` in Platform Configuration) in `ServicePaymentService::initiate()`. If total_amount ≥ threshold, verify the step-up token before proceeding to wallet debit. Throw `StepUpRequiredException` if not provided.

**Rationale**: Constitution Principle 5 requires privileged financial operations to use step-up/MFA at the configurable threshold. The threshold is read from Platform Configuration at runtime — not hard-coded — consistent with E08 and E09 handling.

---

## 8. E11 Transaction Integration

**Decision**: Emit a `ServicePaymentInitiatedEvent` / `ServicePaymentCompletedEvent` / `ServicePaymentFailedEvent` that the E11 Transaction module listens to in order to create a unified transaction history entry. The service module does NOT write directly to the transaction module's tables.

**Rationale**: E11 owns the unified transaction history. Cross-module data flows via events, not direct DB writes. This keeps module boundaries clean and extraction-ready (Constitution Principle 7).
