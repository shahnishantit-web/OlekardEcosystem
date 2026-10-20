# Implementation Plan — E15 Service & Bill Payments

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected
- [x] No TBD value invented (provider selection deferred per Principle 2; config stubs seeded)
- [x] Authorization remains server-side (resource-owner check on wallet; admin permissions on catalog CRUD)
- [x] Financial integrity preserved (double-entry debit + compensating reversal; idempotency key on payment initiation)
- [x] Security/audit requirements incorporated (audit log on CRUD + payment + force-outcome; step-up enforced at threshold)
- [x] UX/design-system requirements incorporated (loading, success, error states per story; mobile-first via Flutter)
- [x] QA strategy incorporated (unit + integration + API + concurrency tests per phase)

---

## Technical Context

- **Backend module**: `App\Modules\Service` (new module, extraction-ready)
- **DB schema**: `service` (PostgreSQL schema-per-module convention)
- **Routes file**: `routes/modules/service.php`
- **Provider**: `App\Modules\Service\Providers\ServiceServiceProvider`
- **Migration range**: `2026_09_30_015000` – `2026_09_30_015599`

### Dependencies on Prior Epics

| Epic | Dependency |
|------|------------|
| E06 Wallet Management | `WalletModuleContract` — balance check, debit/credit |
| E07 Wallet Ledger | `LedgerModuleContract` — post double-entry journals |
| E04 Roles & Permissions | Permission seeding (`service.manage`, `service.list`, `service.pay`) — admin force-outcome uses `service.manage`; `service.payment.resolve` collapsed into `service.manage` |
| E03 Device & Session | Step-up authentication at BWP 10,000 threshold |
| E11 Transaction Management | Service transactions surfaced in unified history via `TransactionModuleContract` |
| E08 Funding | `ServiceProviderAdapter` interface modelled after `FundingProviderAdapter` |
| Platform | Idempotency, Audit, EventPublisher, MetricsCollector, Platform Configuration |

### Provider Adapter Pattern (from E08)

`Domain/Contracts/ServiceProviderAdapter.php` defines (typed DTOs, not raw arrays):
- `validateReference(string $providerCode, string $customerReference): ReferenceValidationResult`
- `submitPayment(string $providerCode, string $transactionId, string $customerReference, int $amount, string $currencyCode, string $idempotencyKey): PaymentSubmissionResult`
- `queryStatus(string $providerCode, string $providerReference): PaymentStatusResult`
- `verifyCallback(string $providerCode, array $payload, array $headers): CallbackVerificationResult`

Concrete: `Infrastructure/Adapters/MockServiceProviderAdapter.php` (dev/test only; real adapters are TBD per Constitution Principle 2).

### Ledger Account References

| Operation | Debit | Credit |
|-----------|-------|--------|
| Payment debit | `wallet:{walletId}` | `service_payable:{currencyCode}` |
| Failure reversal | `service_payable:{currencyCode}` | `wallet:{walletId}` |

---

## Module Structure

```
app/Modules/Service/
├── Application/
│   ├── Services/
│   │   ├── ServiceCatalogService.php        # Admin CRUD: create / update / deactivate / reactivate
│   │   ├── ServiceDiscoveryService.php      # Customer list / search / details
│   │   ├── ServiceValidationService.php     # Customer reference validation via adapter
│   │   ├── ServiceFeeService.php            # Fee calculation (flat or %)
│   │   ├── ServicePaymentService.php        # Payment initiation: create initiated tx → debit → submit
│   │   ├── ServiceOutcomeService.php        # Handle provider success / failure / callback
│   │   ├── ServiceReceiptService.php        # Receipt creation and retrieval
│   │   └── AdminResolutionService.php       # Admin force-outcome for timeout_pending_review
│   └── Jobs/
│       ├── ReQueryTimeoutTransactionJob.php      # Periodic re-query for timeout_pending_review
│       ├── TransitionTimedOutPaymentsJob.php     # pending_provider → timeout_pending_review after timeout
│       └── AbandonStaleTransactionsJob.php       # Transition stale 'initiated' to 'abandoned'
├── Console/
│   └── AbandonStaleServiceTransactionsCommand.php
├── Domain/
│   ├── Contracts/
│   │   └── ServiceProviderAdapter.php
│   ├── Enums/
│   │   ├── ServiceStatus.php               # active, inactive
│   │   ├── ServiceTransactionStatus.php    # initiated, pending_provider, completed, failed, timeout_pending_review, abandoned
│   │   └── ServiceFeeType.php              # flat, percentage
│   ├── Events/
│   │   ├── ServiceCreatedEvent.php
│   │   ├── ServiceUpdatedEvent.php
│   │   ├── ServiceDeactivatedEvent.php
│   │   ├── ServicePaymentInitiatedEvent.php
│   │   ├── ServicePaymentCompletedEvent.php
│   │   └── ServicePaymentFailedEvent.php
│   └── Exceptions/
│       ├── ServiceNotFoundException.php
│       ├── ServiceNotAvailableException.php
│       ├── ServiceTransactionNotFoundException.php
│       ├── InvalidCustomerReferenceException.php
│       ├── ProviderValidationTimeoutException.php
│       ├── InsufficientBalanceException.php
│       ├── FeeChangedException.php
│       ├── StepUpRequiredException.php
│       └── TransactionNotModifiableException.php
├── Infrastructure/
│   ├── Adapters/
│   │   └── MockServiceProviderAdapter.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── ServiceCatalogController.php          # Admin CRUD
│   │   │   ├── ServiceDiscoveryController.php        # Customer list / search / details
│   │   │   ├── ServiceValidationController.php       # validate reference + calculate fee
│   │   │   ├── ServicePaymentController.php          # initiate + get status
│   │   │   ├── ServiceCallbackController.php         # provider inbound callback
│   │   │   ├── ServiceReceiptController.php          # receipt retrieval
│   │   │   └── AdminServiceResolutionController.php  # force-outcome
│   │   ├── Requests/
│   │   │   ├── CreateServiceRequest.php
│   │   │   ├── UpdateServiceRequest.php
│   │   │   ├── ValidateCustomerReferenceRequest.php
│   │   │   ├── CalculateServiceFeeRequest.php
│   │   │   ├── InitiateServicePaymentRequest.php
│   │   │   ├── ServiceCallbackRequest.php
│   │   │   └── AdminForceOutcomeRequest.php
│   │   └── Resources/
│   │       ├── ServiceResource.php
│   │       ├── ServiceTransactionResource.php
│   │       └── ServiceReceiptResource.php
│   ├── Migrations/
│   │   ├── 2026_09_30_015000_create_service_catalog_table.php
│   │   ├── 2026_09_30_015100_create_service_transactions_table.php
│   │   ├── 2026_09_30_015200_create_service_receipts_table.php
│   │   ├── 2026_09_30_015300_create_service_provider_callbacks_table.php
│   │   ├── 2026_09_30_015400_seed_service_permissions.php
│   │   └── 2026_09_30_015500_seed_service_configuration_stubs.php
│   └── Models/
│       ├── ServiceModel.php
│       ├── ServiceTransactionModel.php
│       ├── ServiceReceiptModel.php
│       └── ServiceProviderCallbackModel.php
├── Providers/
│   └── ServiceServiceProvider.php
```

Route file: `wallet-ecosystem/services/api/routes/modules/service.php`

---

## API Routes

### Admin Routes (require `service.manage` permission)

| Method | Path | Controller | Description |
|--------|------|-----------|-------------|
| GET | `/v1/services/admin` | ServiceCatalogController@index | List all services (incl. inactive) |
| POST | `/v1/services/admin` | ServiceCatalogController@store | Create service |
| GET | `/v1/services/admin/{id}` | ServiceCatalogController@show | Get service by ID |
| PATCH | `/v1/services/admin/{id}` | ServiceCatalogController@update | Update service |
| POST | `/v1/services/admin/{id}/deactivate` | ServiceCatalogController@deactivate | Deactivate service |
| POST | `/v1/services/admin/{id}/reactivate` | ServiceCatalogController@reactivate | Reactivate service |
| POST | `/v1/services/admin/transactions/{txId}/resolve` | AdminServiceResolutionController@resolve | Force outcome (timeout_pending_review only) |

### Customer Routes (require `service.list` / `service.pay` permissions)

| Method | Path | Controller | Description |
|--------|------|-----------|-------------|
| GET | `/v1/services` | ServiceDiscoveryController@index | List active services (category/search filter) |
| GET | `/v1/services/{id}` | ServiceDiscoveryController@show | Service details |
| POST | `/v1/services/{id}/validate-reference` | ServiceValidationController@validateReference | Validate customer account reference |
| POST | `/v1/services/{id}/calculate-fee` | ServiceValidationController@calculateFee | Fee quote for an amount |
| POST | `/v1/services/{id}/payments` | ServicePaymentController@store | Initiate payment (`Idempotency-Key` required) |
| GET | `/v1/services/payments/{txId}` | ServicePaymentController@show | Get transaction status |
| GET | `/v1/services/payments/{txId}/receipt` | ServiceReceiptController@show | Get receipt (completed only) |

### Provider Callback Route (provider secret auth)

| Method | Path | Controller | Description |
|--------|------|-----------|-------------|
| POST | `/v1/services/callback/{provider}` | ServiceCallbackController@handle | Inbound provider outcome callback |

---

## Data / Financial Controls

- **Double-entry**: `service_payable:{currencyCode}` liability account registered on first use.
- **Idempotency**: unique constraint on `service_transactions.idempotency_key`; `platform.idempotency_records` stores response for replay.
- **Concurrency**: DB transaction wraps `initiated → pending_provider` status + ledger post; `SELECT FOR UPDATE` on wallet balance check.
- **Compensating entry**: `ServiceOutcomeService::handleFailure()` posts reversal journal atomically with status update to `failed`.
- **Callback idempotency**: unique constraint on `(provider_code, provider_reference)` in `service_provider_callbacks`.

---

## Background Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `AbandonStaleTransactionsJob` | Every 15 min | Transition `initiated` transactions older than config(`service.payment.stale_initiated_timeout_minutes`) to `abandoned` |
| `TransitionTimedOutPaymentsJob` | Every 2 min | Transition `pending_provider` transactions with `updated_at` older than `provider_timeout_seconds` to `timeout_pending_review`; feeds ReQueryTimeoutTransactionJob |
| `ReQueryTimeoutTransactionJob` | Every 5 min | Re-query provider for `timeout_pending_review` transactions; dispatch success/failure if resolved |

---

## Observability

- **Structured log**: `ServicePaymentService`, `ServiceOutcomeService` log at start + completion with `correlationId`, `transactionId`, `customerId`, `serviceId`
- **Metrics**:
  - `service.payment.initiated` (counter)
  - `service.payment.completed` (counter, tag: `service_id`)
  - `service.payment.failed` (counter, tag: `reason`)
  - `service.validation.timeout` (counter)
  - `service.payment.timeout_pending_review` (counter)

---

## Phase 0 — Research

See `research.md`.

## Phase 1 — Design

See `data-model.md`, `contracts/api.yaml`, `quickstart.md`.
