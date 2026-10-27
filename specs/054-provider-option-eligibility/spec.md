# Feature Specification: P05 — Provider Registry and Option Eligibility

**Feature Branch**: `054-provider-option-eligibility`

**Created**: 2026-10-21

**Status**: Draft

**Package**: P05 of the Shared Payment Integration Platform (E48)

**Required Predecessors**: P02 (Payment Schema & API Contracts), P03 (Tenant Isolation & Payment Permissions), P04 (Purpose Validation & Fee Snapshots)

**Source References**: FD03 (Provider adapter contracts); LC01, LC02 (regulatory eligibility constraints); Section 8 (provider registry design)

---

## Context

The Payment module (E48) routes payments through registered provider accounts. Today's `ProviderRegistryService` queries active accounts from the database and maps them to display options. It does not:

- Enforce an adapter allowlist (any string may be stored as `adapter_identifier`)
- Run an ordered eligibility pipeline (platform → org → module → purpose → country → currency → amount)
- Return reasons why a provider is unavailable (needed for support and admin debugging)
- Track which account configuration version was in use when an attempt was initiated (needed for refund/dispute resolution after config changes)
- Validate sensitive draft fields before approval workflows proceed

P05 closes these gaps while preserving backward compatibility with P01–P04 work already shipped.

**Hard boundaries inherited from the platform:**
- No arbitrary executable adapter uploads
- `callback_url` on provider accounts MUST be null (callback ingress is owned by E25); storing an unrestricted external URL is prohibited
- `callback_secret_reference` stores a Vault/Secrets Manager path, never the credential value
- Suspension of a provider blocks NEW attempt initiation but MUST NOT prevent resolution of in-flight attempts, refunds, or disputes

---

## User Scenarios & Testing

### User Story 1 — Ordered Eligibility Evaluation (Priority: P1)

A customer or agent initiates a payment intent and requests available payment options. The system evaluates every registered provider account through an ordered eligibility pipeline and returns only the options that are currently available and suitable for the specific context (amount, currency, organisation, module, purpose type, country). Unavailable options are silently excluded from the consumer-facing response; the request never reveals why a provider was excluded to end-users.

**Why this priority**: Option discovery is called at the start of every payment flow. A broken or inaccurate eligibility gate either blocks legitimate payments or exposes unavailable options that then fail at execution time. Correctness here protects financial integrity downstream.

**Independent Test**: Deploy the updated `ProviderRegistryService` and call `GET /v1/payment/options` with contexts that satisfy or violate each eligibility stage. Verify that only eligible options are returned and that any option excluded in stage N is absent from the response regardless of stages N+1.

**Acceptance Scenarios**:

1. **Given** a provider with `is_enabled = false`, **When** option discovery runs for any context, **Then** that provider's account MUST NOT appear in results.
2. **Given** a provider with `is_suspended = true`, **When** option discovery runs, **Then** that provider's accounts MUST NOT appear in new-option results (but in-flight attempts on that provider continue to resolve normally).
3. **Given** a provider account with `is_active = false`, **When** option discovery runs, **Then** that account MUST NOT appear in results.
4. **Given** a provider account whose `enabled_organisations` list does not contain the requesting org ID (and the list is non-empty), **When** option discovery runs with that org, **Then** that account MUST NOT appear.
5. **Given** a provider account with `enabled_organisations = []` (empty means unrestricted), **When** any org requests options, **Then** the account is still eligible at this stage.
6. **Given** a provider account whose `supported_currencies` does not include the intent currency, **When** option discovery runs, **Then** that account MUST NOT appear.
7. **Given** a provider account with `min_amount_minor = 500` and `max_amount_minor = 10000000`, **When** option discovery runs with `amount = 100` (below minimum), **Then** that account MUST NOT appear.
8. **Given** a provider account with `enabled_purpose_types` containing `['bill_payment']`, **When** option discovery runs with `purpose_type = 'transfer'`, **Then** that account MUST NOT appear.
9. **Given** a provider account with `enabled_purpose_types = []` (unrestricted), **When** any purpose type is requested, **Then** that account is eligible at this stage.
10. **Given** an eligible wallet-provider account and the requesting actor has insufficient wallet balance, **When** option discovery runs, **Then** the option IS returned but `has_sufficient_balance = false`.

---

### User Story 2 — Adapter Allowlist Enforcement (Priority: P2)

A platform administrator attempts to register a new payment provider. The system validates that the supplied `adapter_identifier` is present in the platform's installed adapter allowlist before the provider record is created. If the identifier is not on the allowlist, the request is rejected with a structured error. Administrators can also query the allowlist to see which adapters are available for registration.

**Why this priority**: An uncontrolled adapter registry could allow misconfigured or non-existent adapters to enter the live system, causing payment attempts to fail silently. The allowlist is the first defence of the financial execution boundary.

**Independent Test**: Call `POST /v1/payment/providers` with both an allowed identifier (`olekard_wallet`, `mock_bank`) and a disallowed one (`unknown_bank`). Verify the allowed create succeeds and the disallowed one returns a 422 with error code `ADAPTER_NOT_IN_ALLOWLIST`. Call `GET /v1/payment/providers/adapters` and verify the response lists the permitted identifiers.

**Acceptance Scenarios**:

1. **Given** an admin submits `adapter_identifier = 'olekard_wallet'`, **When** `POST /v1/payment/providers` is called, **Then** the provider is created successfully.
2. **Given** an admin submits `adapter_identifier = 'rogue_gateway'` (not in allowlist), **When** `POST /v1/payment/providers` is called, **Then** the response is HTTP 422 with `error_code = ADAPTER_NOT_IN_ALLOWLIST` and no provider record is created.
3. **Given** the allowlist is extended by adding `'mobile_money_provider'` to the platform config, **When** a provider with that identifier is registered, **Then** it succeeds.
4. **Given** an unauthenticated caller, **When** `GET /v1/payment/providers/adapters` is called, **Then** HTTP 401 is returned.
5. **Given** an authenticated admin with `payment.admin` permission, **When** `GET /v1/payment/providers/adapters` is called, **Then** the list of allowed adapter identifiers is returned.

---

### User Story 3 — Configuration Versioning and Attempt Snapshot (Priority: P2)

Every time a provider account's configuration is modified (directly or through an approved change draft), the account's `config_version` increments. When a payment attempt is initiated, the system captures the current `config_version` of the selected account. Later, if the account's configuration changes or the provider is suspended, historical attempts retain a reference to the configuration that was active when they were created, enabling correct refund routing and dispute evidence gathering regardless of subsequent provider state.

**Why this priority**: Without version snapshots, a refund initiated after a provider account is updated may fail because the execution layer tries to re-validate eligibility against current (possibly suspended) config. Versioning preserves the contractual basis of each attempt.

**Independent Test**: Create a provider account (version=1), initiate an attempt (captures version=1), update the account config (version becomes 2), then verify the attempt record still shows `provider_account_version = 1`. Suspend the provider and verify the attempt can still be retrieved and transitioned to a terminal state.

**Acceptance Scenarios**:

1. **Given** a provider account at `config_version = 1`, **When** the account is updated (non-sensitive field), **Then** `config_version` becomes `2`.
2. **Given** a provider account at `config_version = 3`, **When** a payment attempt is initiated using that account, **Then** the attempt record stores `provider_account_version = 3`.
3. **Given** a provider is suspended after an attempt is in `Pending` state, **When** the attempt is resolved (confirmed or failed), **Then** the resolution succeeds and is NOT blocked by the suspension check.
4. **Given** a provider account at version 2 has its config changed to version 3, **When** the version-2 attempt is refunded, **Then** the refund uses the attempt's captured `provider_account_version` reference and does not re-evaluate current eligibility.
5. **Given** a sensitive change draft (e.g., `callback_secret_reference` rotation) is approved, **When** the change is applied, **Then** `config_version` increments and an audit entry records the new version.

---

### User Story 4 — Capability and Display Metadata (Priority: P3)

Provider accounts declare structured capability flags (e.g., `supports_refund`, `supports_step_up`, `supports_qr`) using a validated enumerated set, not arbitrary strings. Consumer-facing option discovery returns capability flags and display metadata (icon key, description, badge) so the client application can render options with appropriate UI treatment. Capability-gated options (e.g., a QR payment context) are automatically excluded when the required capability is absent.

**Why this priority**: Structured capabilities replace implicit adapter-level knowledge in client code. Display metadata enables consistent UI without hardcoding provider names in the frontend. This is a quality-of-life improvement that does not block financial flows.

**Independent Test**: Register a provider account with `capability_flags = {supports_refund: true, supports_qr: false}` and `display_description = 'Pay from your OleKard wallet'`. Call option discovery with a context that requires `supports_qr`. Verify the account is excluded. Call discovery without that requirement and verify the account is included with `display_description` in the response.

**Acceptance Scenarios**:

1. **Given** a provider account with `supports_qr = false`, **When** option discovery runs with `required_capability = 'supports_qr'`, **Then** that account is excluded from results.
2. **Given** a provider account with `display_description = 'Pay from your OleKard wallet'` and `display_icon_key = 'icon.payment.wallet'`, **When** the account is returned by option discovery, **Then** the response includes `display_description` and `display_icon_key`.
3. **Given** an admin submits `capability_flags` with an unknown key (e.g., `supports_teleportation`), **When** the account is created or updated, **Then** the response is HTTP 422 with `error_code = UNKNOWN_CAPABILITY_FLAG`.
4. **Given** a provider account has `display_badge = 'RECOMMENDED'`, **When** option discovery runs, **Then** the returned option includes `display_badge = 'RECOMMENDED'`.

---

### User Story 5 — Admin Eligibility Preview (Priority: P3)

A platform administrator can call a preview endpoint with any combination of eligibility context parameters (org, module, purpose type, country, currency, amount) and receive a complete list of ALL registered provider accounts annotated with eligibility status and, for ineligible accounts, the specific reason(s) for exclusion. This preview is read-only and does not create any intent or attempt.

**Why this priority**: Support teams and integration engineers need to debug eligibility decisions without having to trace through code. The preview turns the black-box eligibility filter into a transparent diagnostic tool.

**Independent Test**: Configure one account eligible and two accounts ineligible (one due to currency, one due to suspension). Call `GET /v1/payment/providers/eligibility-preview?currency=BWP&amount=1000&...` and verify the response includes all three accounts: one with `is_eligible = true` and two with `is_eligible = false` plus the specific failure reason.

**Acceptance Scenarios**:

1. **Given** an admin with `payment.admin` permission calls `GET /v1/payment/providers/eligibility-preview` with a context, **Then** the response lists all provider accounts with `is_eligible` and `ineligibility_reason` fields.
2. **Given** an account fails the currency stage, **When** the preview is called, **Then** `ineligibility_reason = 'CURRENCY_NOT_SUPPORTED'` is returned for that account.
3. **Given** an account fails the org stage, **When** the preview is called, **Then** `ineligibility_reason = 'ORGANISATION_NOT_ENABLED'` is returned.
4. **Given** a non-admin caller (missing `payment.admin` permission), **When** `GET /v1/payment/providers/eligibility-preview` is called, **Then** HTTP 403 is returned and no account details are exposed.
5. **Given** a provider is suspended, **When** the preview is called for new-execution context, **Then** the account appears with `is_eligible = false` and `ineligibility_reason = 'PROVIDER_SUSPENDED'`.

---

### User Story 6 — Draft Validation Before Approval (Priority: P3)

When an admin submits a provider account change draft (e.g., updating `callback_secret_reference` or `supported_currencies`), the system validates the proposed values against field-level rules before storing the draft. If validation fails, the draft is rejected immediately with structured field errors — not silently stored as an invalid draft. Validation rules include: secret reference path format, known ISO 4217 currency codes, valid capability flag keys, and the prohibition on direct `callback_url` values (E25 owns callback ingress).

**Why this priority**: Invalid drafts entering an approval queue waste reviewer time and may cause failures during apply. Front-loading validation reduces the review cycle and prevents invalid config from ever reaching live accounts.

**Independent Test**: Submit a change draft with `callback_secret_reference = 'plaintext-password'` (invalid — not a Vault path) and with `supported_currencies = ['BWP', 'INVALID']`. Verify both are rejected with field-level error messages before a draft record is created.

**Acceptance Scenarios**:

1. **Given** a draft contains `callback_secret_reference = 'plaintext-password'` (not a valid secret path), **When** the draft is submitted, **Then** HTTP 422 is returned with `field = 'callback_secret_reference'` and `error = 'INVALID_SECRET_REFERENCE_FORMAT'`, and no draft record is created.
2. **Given** a draft contains `supported_currencies = ['BWP', 'INVALID_CODE']`, **When** the draft is submitted, **Then** HTTP 422 is returned indicating `INVALID_CURRENCY_CODE` for `INVALID_CODE`.
3. **Given** a draft contains `callback_url = 'https://external-bank.example.com/callback'`, **When** the draft is submitted, **Then** HTTP 422 is returned with `error = 'CALLBACK_URL_NOT_PERMITTED'` (E25 owns ingress).
4. **Given** a draft contains all valid values, **When** it is submitted, **Then** a draft record is created in `PendingReview` state and the response includes the draft ID.
5. **Given** a valid draft is approved by an authorized reviewer, **When** the change is applied, **Then** the account's `config_version` increments, an audit event is recorded, and the draft status transitions to `Applied`.

---

### Edge Cases

- What happens when `enabled_organisations`, `enabled_modules`, or `enabled_purpose_types` contains entries that are no longer valid (deleted org, removed module)? Answer: eligibility evaluation uses exact-match against the requesting context value. Stale entries do not block unrelated contexts; they simply have no effect if no request ever presents that exact value.
- What happens if two admins concurrently approve different drafts for the same account? Answer: the second approval sees the version counter has changed since the draft was created, and the draft is rejected with a `CONFIGURATION_VERSION_CONFLICT` error.
- What happens when the adapter allowlist config is empty or missing? Answer: the allowlist service defaults to the hardcoded fallback list (`olekard_wallet`, `mock_bank`) and emits a configuration warning. An empty allowlist (explicitly `[]`) is treated as "no adapters permitted" (fail closed).
- What happens when option discovery is called with a zero-amount context? Answer: accounts with `min_amount_minor > 0` are excluded; accounts with `min_amount_minor = 0` are included. The pipeline treats amount=0 as a valid request (e.g., free-of-charge services).
- Can a provider account version go backwards? Answer: No. Version is an append-only counter. A rejected draft that is re-submitted after approval does not decrement the counter.

---

## Requirements

### Functional Requirements

- **FR-001**: The eligibility pipeline MUST evaluate provider accounts in this exact order: (1) platform restrictions (enabled, suspended, active), (2) organisation eligibility, (3) module eligibility, (4) purpose-type eligibility, (5) country eligibility, (6) currency eligibility, (7) amount range eligibility, (8) capability eligibility. A provider account failing any stage MUST be excluded without evaluating later stages.
- **FR-002**: `GET /v1/payment/options` MUST return only accounts that pass all eligibility stages for the supplied context. The response MUST NOT include ineligibility reasons.
- **FR-003**: `PaymentProviderAdminService::createProvider()` MUST validate `adapter_identifier` against the installed allowlist before inserting. An unrecognised identifier MUST return error code `ADAPTER_NOT_IN_ALLOWLIST`.
- **FR-004**: The adapter allowlist MUST be configurable via platform config key `payment.adapter_allowlist` (JSON array). When absent, the system MUST fall back to `['olekard_wallet', 'mock_bank']`.
- **FR-005**: `payment_provider_accounts` MUST have a `config_version` integer column (default 1). Every approved or direct update to an account MUST increment `config_version` within the same database transaction.
- **FR-006**: `payment_attempts` MUST record `provider_account_version` (the `config_version` value at initiation time). This column MUST be populated by the orchestration service when the attempt is first created.
- **FR-007**: Attempt resolution (confirm, fail, refund initiation) MUST NOT re-evaluate provider eligibility against current config. It MUST use the captured `provider_account_version` as an evidence reference only.
- **FR-008**: Draft validation MUST run synchronously at submission time and MUST reject drafts that contain: (a) `callback_secret_reference` not matching `vault://` or `secretsmanager://` prefix, (b) `callback_url` (any non-null value — E25 owns ingress), (c) unrecognised ISO 4217 currency codes in `supported_currencies`, (d) unknown capability flag keys in `capability_flags`.
- **FR-009**: The `capability_flags` JSONB MUST only accept keys from the approved capability set: `supports_refund`, `supports_webhook`, `supports_step_up`, `supports_qr`, `supports_escrow`. Additional keys MUST be rejected with `UNKNOWN_CAPABILITY_FLAG`.
- **FR-010**: The `supported_payment_methods` JSONB MUST only accept values from the approved instrument set: `wallet_transfer`, `bank_transfer`, `card_payment`, `qr_payment`, `mobile_money`. Unknown values MUST be rejected.
- **FR-011**: Provider account records MUST support display metadata fields: `display_description` (string, nullable, max 255), `display_icon_key` (string, nullable, max 128 — design-system token key, not a URL), `display_badge` (string, nullable, max 64).
- **FR-012**: Provider accounts MUST support `enabled_purpose_types` JSONB (default `[]` = unrestricted). When non-empty, option discovery MUST exclude accounts where the requested `purpose_type` is not in the list.
- **FR-013**: `GET /v1/payment/providers/eligibility-preview` MUST require `payment.admin` permission and MUST return all registered accounts annotated with `is_eligible` (bool) and `ineligibility_reason` (string|null using the defined reason enum).
- **FR-014**: `GET /v1/payment/providers/adapters` MUST require `payment.admin` permission and MUST return the current allowlist.
- **FR-015**: All configuration changes to provider accounts (direct or via draft approval) MUST produce an audit entry including the before-state `config_version` and after-state `config_version`.
- **FR-016**: A concurrent draft conflict (two drafts approved for the same account version) MUST be detected and the second approval MUST fail with `CONFIGURATION_VERSION_CONFLICT`.
- **FR-017**: The `PaymentConfigChangeDraftModel` draft submission endpoint MUST return field-level validation errors when any field in `change_payload` fails validation rules (FR-008).

### Key Entities

- **AdapterAllowlist**: Platform-level list of permitted adapter identifiers. Seeded from config `payment.adapter_allowlist`; has a hardcoded fallback. Not a database table — resolved by a service class at runtime.
- **ProviderAccountCapabilities**: The enumerated set of valid capability flag keys. Expressed as a PHP-backed enum. Values: `supports_refund`, `supports_webhook`, `supports_step_up`, `supports_qr`, `supports_escrow`.
- **PaymentInstrumentType**: The enumerated set of valid payment method / instrument type values. Values: `wallet_transfer`, `bank_transfer`, `card_payment`, `qr_payment`, `mobile_money`.
- **EligibilityResult**: Value object carrying `isEligible: bool` and `reason: ?EligibilityReason`. Not persisted.
- **EligibilityReason** (enum): `PROVIDER_DISABLED`, `PROVIDER_SUSPENDED`, `ACCOUNT_INACTIVE`, `ORGANISATION_NOT_ENABLED`, `MODULE_NOT_ENABLED`, `PURPOSE_TYPE_NOT_ENABLED`, `COUNTRY_RESTRICTED`, `CURRENCY_NOT_SUPPORTED`, `AMOUNT_BELOW_MINIMUM`, `AMOUNT_ABOVE_MAXIMUM`, `CAPABILITY_NOT_SUPPORTED`.
- **PaymentProviderAccount** (modified): Adds `config_version`, `enabled_purpose_types`, `display_description`, `display_icon_key`, `display_badge`.
- **PaymentAttempt** (modified): Adds `provider_account_version`.

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: Option discovery returns only accounts passing all 8 eligibility stages for any supplied context; verified by unit tests covering each stage boundary independently.
- **SC-002**: Creating a provider with a non-allowlisted adapter identifier returns HTTP 422 with `ADAPTER_NOT_IN_ALLOWLIST` in under 100 ms.
- **SC-003**: Attempt resolution (confirm/fail) on a suspended provider's in-flight attempt succeeds without error; no new options from the suspended provider appear in concurrent option discovery.
- **SC-004**: `config_version` on an account is monotonically non-decreasing across all update operations; verified by a concurrency test simulating two concurrent draft approvals.
- **SC-005**: Every new attempt record in the database has a non-null `provider_account_version`; verified by integration test.
- **SC-006**: The eligibility preview endpoint returns the full annotated list for an admin and HTTP 403 for a non-admin; response time < 500 ms for up to 20 registered accounts.
- **SC-007**: Draft submission with any invalid field value (as defined in FR-008) is rejected at the API layer before any database write; verified by unit tests per validation rule.
- **SC-008**: PHPStan level 8 produces 0 errors on all new and modified files.

---

## Assumptions

- FD03 (Provider adapter contracts) is not yet in the repository; the adapter allowlist is seeded from the known installed adapters (`olekard_wallet`, `mock_bank`) and extended via platform config. No additional adapter identifiers are invented.
- LC01 and LC02 may add country-level eligibility restrictions; however, their specific content is AWAITING_DOCUMENT. The `country_restrictions` field on the account and the COUNTRY_RESTRICTED reason enum value are the correct hooks. Implementation of LC01/LC02 business logic beyond opaque storage is blocked (FR-B01/FR-B02 from P03 inheritance).
- The `callback_url` column on `payment_provider_accounts` was created in P02 migrations. P05 adds a validation rule that it MUST be null (E25 owns external callback ingress). Existing rows with a non-null value are not migrated — they are an operator data quality issue to resolve via the admin UI.
- Draft approval workflows (maker-checker) use the existing `PaymentConfigChangeDraftModel` infrastructure from P02. P05 adds front-end draft validation and version conflict detection; it does not rebuild the approval workflow.
- `display_icon_key` stores a design-system token key (e.g., `icon.payment.wallet`), not an external URL. The frontend resolves the key to an asset. This satisfies the "no unrestricted endpoint URLs" boundary.
- The `enabled_purpose_types` restriction is evaluated against the `purpose_type` supplied in the `OptionsQuery`. It is NOT evaluated against the full obligation graph (that remains P04's domain).
- The `config_version` conflict detection for concurrent draft approvals uses an optimistic-lock check: the draft records the account's `config_version` at draft-creation time; the approval service re-reads the account's current version inside a transaction and rejects if they differ.
- `PaymentAttemptModel::$provider_account_version` is nullable for backward compatibility with attempts created before P05 ships. New attempts MUST populate it.
