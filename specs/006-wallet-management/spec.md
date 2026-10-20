# E06 — Wallet Management

**Feature**: `006-wallet-management` | **Epic**: E06 | **Status**: Specified | **Created**: 2026-09-16

---

## 1. Problem Statement

E05 (KYC) establishes a verified identity for Customers and Agents. However, there is currently no wallet domain: no wallet entity, no currency pocket structure, no balance architecture, and no wallet lifecycle management. Without E06, no financial operations (funding, transfers, withdrawals, payments) are possible. Every downstream financial epic (E07–E29) depends on a well-formed, multi-currency wallet as its financial substrate.

E06 establishes the core **Wallet Management** domain: the wallet entity, currency pocket architecture, wallet lifecycle state machine, balance views, wallet limits, freeze/unfreeze/close operations, and the integration contracts that downstream epics consume. It also wires the wallet creation trigger to the `kyc.case.approved` event emitted by E05, ensuring that every KYC-approved user automatically receives a provisioned wallet.

The ledger entry mechanics (debit/credit journals, balance calculation from entries) are owned by E07. E06 owns the wallet and pocket structure; E07 owns the entries that populate those pockets.

---

## 2. Scope

E06 covers:

- **Wallet entity lifecycle**: creation (auto-triggered by KYC approval), ID assignment, user association, activation, suspension, restriction, freeze, unfreeze, close
- **Currency pockets**: one pocket per supported currency per wallet; extensible through configuration; baseline currencies BWP (primary), USD, EUR, ZAR, CNY, USDT
- **Balance architecture**: available balance, ledger balance, held/reserved balance, pending balance — all derived from authoritative ledger data; no mutable balance fields
- **Balance enforcement**: prevent any debit that would exceed available balance; perform limit checks before ledger posting
- **Wallet limits**: configurable per-wallet, per-pocket limits (daily, weekly, monthly transaction limits; single-transaction limit; maximum balance); Admin-managed
- **Wallet status history**: immutable record of every status transition
- **Permission seeding**: `wallet.*` permissions assigned to appropriate roles via migration
- **Configuration entries**: all configurable policy values seeded with TBD markers
- **WalletModuleContract expansion**: the cross-module interface extended to serve downstream epics
- **Domain events**: `wallet.created`, `wallet.activated`, `wallet.pocket.added`, `wallet.status.changed`, `wallet.frozen`, `wallet.unfrozen`, `wallet.closed`, `wallet.limits.configured`

---

## 3. Goals

1. Provide a complete, auditable wallet and currency-pocket lifecycle that every downstream financial epic can rely on.
2. Enforce financial integrity from Day 1: no mutable balance fields, no ad-hoc balance mutations; all balances derive from the immutable ledger (E07).
3. Establish multi-currency pocket architecture supporting BWP, USD, EUR, ZAR, CNY, USDT at baseline, extensible without code changes.
4. Give privileged actors (Admin, Compliance) governed, auditable controls to freeze, suspend, restrict, or close wallets.
5. Prevent overdrafts and limit violations before any ledger posting occurs.
6. Emit domain events so downstream epics can react without polling.
7. Keep the `Wallet` module boundary explicit and extraction-ready per constitution Principle 7.
8. Establish the `wallet` PostgreSQL schema and all DB entities required for downstream epics.

---

## 4. Non-Goals

E06 does NOT:

- Implement debit/credit ledger journal entries (E07 Wallet Ledger).
- Implement wallet funding / cash-in (E08 Wallet Funding).
- Implement wallet-to-wallet transfers (E09).
- Implement withdrawals / cash-out (E10).
- Implement transaction management or transaction history beyond wallet-status-history (E11).
- Implement FX conversion, exchange rates, or cross-currency operations (E29 Multi-Currency).
- Implement fees, commissions, or pricing (E21).
- Implement fraud, AML, or risk scoring (E18).
- Implement the Admin Portal wallet management UI screens (E33 Admin Application).
- Implement the Customer or Agent mobile wallet screens beyond what requires a backend API (E31, E32).
- Implement reconciliation or settlement (E20).
- Implement step-up / MFA authentication flow (E01/E03 — consumed via existing contract).
- Hard-code any configurable business values (limits, thresholds, rates).

---

## 5. Actors

| Actor | Description | Wallet Permissions |
|---|---|---|
| **Customer** | KYC-approved end-user | Read own wallet; view own balances; add currency pockets; initiate standard operations |
| **Agent** | KYC-approved field agent | Same wallet capabilities as Customer for own wallet |
| **Admin** | Admin user with elevated permissions | Full wallet management: read any wallet, configure limits, freeze, unfreeze, close, change status |
| **Compliance Officer** | Admin user with compliance permissions | Freeze/unfreeze wallets for compliance reasons |
| **System / Event Subscriber** | Background process responding to domain events | Auto-creates wallets on `kyc.case.approved` |

---

## 6. User Stories

### US-WALLET-001 — Automatically Create Wallet (P0)

**Actor**: Customer / authorized financial service

**Story**: As a system event subscriber, I want to automatically provision a wallet when a Customer's KYC case is approved, so that the Customer can immediately proceed to financial operations without manual intervention.

**Why this priority**: Wallet creation is the entry point for all downstream financial epics. Without it, no other E06–E29 story is reachable.

**Independent Test**: Publish a `kyc.case.approved` event for a user with no existing wallet → a wallet record is created with `status = pending`, a BWP pocket is initialized, and `wallet.created` + `wallet.pocket.added` events appear in the outbox.

**Acceptance Scenarios**:

1. **Given** a `kyc.case.approved` event is received for user `U` who has no existing wallet, **When** the event is processed, **Then** a wallet with `status = pending` and an associated default BWP pocket is created; the wallet is associated with user `U`; a `wallet.created` event is emitted to the outbox; `wallet_status_history` records the initial `pending` status.
2. **Given** a `kyc.case.approved` event is received for user `U` who already has an active wallet, **When** the event is processed, **Then** no duplicate wallet is created; processing is idempotent and logs a structured warning.
3. **Given** a `kyc.case.approved` event is received and wallet creation fails (e.g., DB error), **When** the failure occurs, **Then** no partial wallet record is persisted; the operation is atomic; the event can be safely replayed.
4. **Given** the same event is delivered twice (at-least-once delivery), **When** the second delivery is processed, **Then** the wallet created by the first delivery is returned; no duplicate is created.
5. **Given** an Admin calls `POST /v1/wallets` with valid actor credentials and a user_id, **When** the request is processed, **Then** a wallet is created for that user (if not already existing) with `status = pending`, idempotent on `Idempotency-Key`.

---

### US-WALLET-002 — Assign Wallet ID (P0)

**Actor**: Customer / authorized financial service

**Story**: As a financial service, I want to assign a unique, human-readable wallet reference number to every wallet so that it can be referenced in transactions, statements, and support interactions.

**Independent Test**: Create a wallet → the response contains both a UUID (`id`) and a unique, non-null `wallet_reference`; two separate wallet creations produce different `wallet_reference` values.

**Acceptance Scenarios**:

1. **Given** a wallet is being created, **When** the creation succeeds, **Then** a unique `wallet_reference` is assigned atomically with the wallet record; two wallets never share the same reference.
2. **Given** a wallet exists, **When** its details are retrieved, **Then** `wallet_reference` is present in the response and is non-null.
3. **Given** a wallet_reference already exists in the system, **When** a new wallet attempts to use the same reference, **Then** a conflict is detected and a new unique reference is generated; no duplicate is persisted.
4. **Given** wallet creation is retried due to a transient failure (same `Idempotency-Key`), **When** the retry succeeds, **Then** the same `wallet_reference` is returned; no duplicate reference is created.

---

### US-WALLET-003 — Associate Wallet with User (P0)

**Actor**: Customer / authorized financial service

**Story**: As a financial service, I want to link a wallet to a specific user account so that ownership and authorization scope are enforced throughout the system.

**Independent Test**: Create a wallet for user `U` → `GET /v1/wallets/{wallet_id}` returns `owner_id = U`; `WalletModuleContract::ownerActorId(wallet_id)` returns `U`; user `V` calling the same endpoint receives HTTP 404.

**Acceptance Scenarios**:

1. **Given** a wallet is created for user `U`, **When** the wallet details are queried by `U`, **Then** the response includes `owner_id` equal to `U`'s UUID.
2. **Given** a wallet exists for user `U`, **When** user `V` attempts to read it, **Then** HTTP 404 is returned (IDOR prevention).
3. **Given** user `U` has one active wallet, **When** an attempt is made to create a second active wallet for `U`, **Then** HTTP 409 `WALLET_ALREADY_EXISTS` is returned.
4. **Given** `WalletModuleContract::ownerActorId(wallet_id)` is called for a non-existent wallet, **When** the contract is invoked, **Then** `null` is returned.
5. **Given** a wallet is created but not yet activated, **When** `WalletModuleContract::isActive(wallet_id)` is called, **Then** `false` is returned.

---

### US-WALLET-004 — Set Wallet Currency (P0)

**Actor**: Customer / authorized financial service

**Story**: As a Customer, I want to enable a currency pocket on my wallet so that I can hold and transact in that currency.

**Independent Test**: Call `POST /v1/wallets/{wallet_id}/pockets` with `currency_code = USD` → HTTP 201, a USD pocket with `status = active` is created; a second call with the same currency returns HTTP 409 `POCKET_ALREADY_EXISTS`.

**Acceptance Scenarios**:

1. **Given** a wallet with only a BWP pocket, **When** a request is made to add a USD pocket, **Then** a new USD pocket is created with `status = active`; the BWP pocket is unaffected; `wallet.pocket.added` event is emitted.
2. **Given** a wallet already has a USD pocket, **When** a request to add a USD pocket is made again, **Then** HTTP 409 `POCKET_ALREADY_EXISTS` is returned; no duplicate pocket is created.
3. **Given** a request to add a pocket for currency code `XYZ` (not in the supported list), **When** the request is processed, **Then** HTTP 422 `UNSUPPORTED_CURRENCY` is returned.
4. **Given** a disabled currency (admin has disabled it via configuration), **When** a request to add that currency's pocket is made, **Then** HTTP 422 `CURRENCY_NOT_AVAILABLE` is returned.
5. **Given** a pocket is added and then the wallet is retrieved, **When** the pockets list is queried, **Then** the response lists all pockets with their currency codes, statuses, and precision values.

---

### US-WALLET-005 — Set Wallet Status (P0)

**Actor**: Admin / authorized financial service

**Story**: As an Admin, I want to transition a wallet between lifecycle states so that access controls and transaction eligibility reflect the wallet's operational condition.

**Independent Test**: Activate a `pending` wallet → status becomes `active`; attempt to activate an already-active wallet with the same `Idempotency-Key` → idempotent response, no duplicate history.

**Acceptance Scenarios**:

1. **Given** a wallet in `pending` status, **When** an authorized Admin calls `PATCH /v1/wallets/{id}/status` with `status = active`, **Then** the wallet transitions to `active`; a status history entry is recorded; `wallet.activated` event is emitted.
2. **Given** a wallet in `active` status, **When** an Admin transitions it to `suspended`, **Then** the transition is recorded; the wallet's status becomes `suspended`; `wallet.status.changed` event is emitted.
3. **Given** an invalid transition (e.g., `closed` → `active`), **When** the transition is requested, **Then** HTTP 422 `INVALID_WALLET_TRANSITION` is returned; no state change occurs.
4. **Given** an unauthorized actor (Customer) attempts to change wallet status arbitrarily, **When** the request is processed, **Then** HTTP 403 is returned.
5. **Given** a status change is requested with the same `Idempotency-Key` as a prior successful change, **When** the duplicate is processed, **Then** the original response is returned; no duplicate history entry is created.

---

### US-WALLET-006 — Configure Wallet Limits (P0)

**Actor**: Admin

**Story**: As an Admin, I want to configure transaction and balance limits on a wallet so that financial risk is bounded and configurable without code deployments.

**Independent Test**: Call `PUT /v1/wallets/{id}/limits` with a daily transaction limit → HTTP 200; subsequent transactions exceeding that daily limit return HTTP 422 `TRANSACTION_LIMIT_EXCEEDED`.

**Acceptance Scenarios**:

1. **Given** a wallet with no explicit limits, **When** an Admin sets a `daily_transaction_amount` limit for the BWP pocket, **Then** the limit is persisted; subsequent debit operations exceeding the daily aggregate are rejected before ledger posting; `wallet.limits.configured` event is emitted.
2. **Given** a limit is already configured, **When** the limit is updated, **Then** the new value takes effect immediately for subsequent transactions.
3. **Given** a limit update request with invalid data (e.g., negative limit value), **When** validated, **Then** HTTP 422 is returned; no partial update occurs.
4. **Given** a wallet is `frozen` or `closed`, **When** a limit update is attempted, **Then** HTTP 422 `WALLET_NOT_EDITABLE` is returned.
5. **Given** limit configuration is changed, **When** `platform.audit_events` is queried, **Then** the audit record shows actor, wallet_id, old limit, new limit, timestamp, and correlation_id.

---

### US-WALLET-007 — View Available Balance (P0)

**Actor**: Customer / authorized financial service

**Story**: As a Customer, I want to see my available balance so that I know how much I can spend or transfer right now.

**Independent Test**: With a pocket having `ledger_balance = 500` BWP and `held_amount = 50` BWP → `GET /v1/wallets/{id}/pockets/BWP/balance` returns `available_balance = 450`.

**Acceptance Scenarios**:

1. **Given** a pocket with `ledger_balance = 200` BWP and `held_amount = 30` BWP, **When** a balance inquiry is made, **Then** `available_balance = 170 BWP` is returned.
2. **Given** a pocket with zero balance and zero holds, **When** a balance inquiry is made, **Then** `available_balance = 0` is returned (not an error).
3. **Given** an unauthenticated request, **When** a balance is queried, **Then** HTTP 401 is returned.
4. **Given** a Customer queries another Customer's wallet balance, **When** the request is processed, **Then** HTTP 404 is returned.
5. **Given** a frozen wallet, **When** an authorized owner queries balance, **Then** the balance is returned (read access is not blocked by freeze); a flag indicating `wallet_status: frozen` is included in the response.

---

### US-WALLET-008 — View Ledger Balance (P0)

**Actor**: Wallet / Ledger service

**Story**: As a Wallet or Ledger service, I want to retrieve the authoritative ledger balance for a pocket so that other components can compute accurate available balances.

**Independent Test**: Sum all credit and debit ledger entries for a pocket via E07 → the result equals the value returned by the ledger balance field in `GET /v1/wallets/{id}/pockets/{code}/balance`.

**Acceptance Scenarios**:

1. **Given** a pocket with 3 credit entries (100, 200, 50 minor units) and 1 debit entry (75), **When** ledger balance is queried, **Then** `ledger_balance = 275` is returned.
2. **Given** a pocket with no entries, **When** queried, **Then** `ledger_balance = 0` is returned.
3. **Given** a ledger balance query, **When** the response is returned, **Then** it includes `currency_code`, `precision`, `ledger_balance` (integer minor unit), and `as_of_timestamp`.
4. **Given** a concurrent debit posting is in-flight, **When** ledger balance is queried, **Then** the response is consistent (either before or after the posting; no partial read).
5. **Given** an unauthorized actor calls the balance endpoint, **When** processed, **Then** HTTP 403 or HTTP 404 is returned appropriately.

---

### US-WALLET-009 — View Held Balance (P0)

**Actor**: Customer / authorized financial service

**Story**: As a Customer, I want to see the held/reserved portion of my balance so that I understand which funds are temporarily unavailable pending transaction settlement.

**Independent Test**: Initiate a transaction that places a hold of 100 BWP → the balance response returns `held_amount = 100`.

**Acceptance Scenarios**:

1. **Given** two active holds of 40 BWP and 60 BWP on a pocket, **When** held balance is queried, **Then** `held_amount = 100 BWP` is returned.
2. **Given** a hold is released after transaction settlement, **When** held balance is queried, **Then** `held_amount` is reduced by the released hold amount.
3. **Given** a pocket with no active holds, **When** queried, **Then** `held_amount = 0` is returned.
4. **Given** a Customer queries held balance on their pocket, **When** the response is returned, **Then** it includes a list of individual hold records (hold_id, amount, created_at, description) for transparency.
5. **Given** a frozen wallet, **When** held balance is queried by the owner, **Then** the response includes all active holds; freeze does not release existing holds.

---

### US-WALLET-010 — View Pending Balance (P1)

**Actor**: Customer / authorized financial service

**Story**: As a Customer, I want to see my pending balance so that I know how much is in transit (initiated but not yet posted to the ledger).

**Independent Test**: With 2 transactions in `initiated` state (amounts 80 BWP and 120 BWP), the balance response shows `pending_balance = 200 BWP`.

**Acceptance Scenarios**:

1. **Given** two outbound transactions in `initiated` state, **When** balance is queried, **Then** `pending_balance` equals the sum of their amounts.
2. **Given** a transaction transitions from `initiated` to `completed`, **When** balance is queried, **Then** the completed transaction is no longer included in pending balance.
3. **Given** no pending transactions, **When** balance is queried, **Then** `pending_balance = 0` is returned.
4. **Given** a balance response includes both inbound and outbound pending transactions, **When** the response is returned, **Then** the sign convention (separate `pending_inbound` / `pending_outbound` fields, or a net `pending_balance` with direction indicator) is clearly defined in the API contract.
5. **Given** an unauthorized actor queries pending balance, **When** processed, **Then** HTTP 404 (cross-user) or HTTP 401 (unauthenticated) is returned.

---

### US-WALLET-011 — Calculate Available Balance (P0)

**Actor**: Customer / authorized financial service

**Story**: As a financial service, I want a reliable available-balance calculation that accounts for held funds so that no financial operation is approved without confirming sufficient available funds.

**Independent Test**: With `ledger_balance = 300` and `held_amount = 80` → `available_balance = 220`; with `held_amount = 80` and `ledger_balance = 80` → `available_balance = 0` (not negative).

**Acceptance Scenarios**:

1. **Given** `ledger_balance = 300` and `held_amount = 80`, **When** available balance is calculated, **Then** `available_balance = 220`.
2. **Given** `held_amount >= ledger_balance`, **When** available balance is calculated, **Then** `available_balance = 0`; it is never negative.
3. **Given** a newly created pocket with no entries and no holds, **When** available balance is calculated, **Then** `available_balance = 0`.
4. **Given** available balance is calculated for a frozen wallet, **When** the calculation is performed, **Then** the mathematical result is correct; freeze does not alter the balance value, only transaction eligibility.
5. **Given** a concurrent hold placement races with available balance calculation, **When** both complete, **Then** the available balance reflects a consistent state (no race-induced negative balance permitted).

---

### US-WALLET-012 — Prevent Transaction Exceeding Available Balance (P0)

**Actor**: Customer / authorized financial service

**Story**: As a financial service, I want every debit request to be checked against available balance before posting so that no wallet ever goes below zero due to overdraft.

**Independent Test**: With `available_balance = 100 BWP`, attempt debit of 101 BWP → HTTP 422 `INSUFFICIENT_BALANCE`; the ledger has zero new entries after the rejection.

**Acceptance Scenarios**:

1. **Given** a pocket with `available_balance = 100`, **When** a debit of 100 is requested, **Then** the debit is permitted (equal to available balance is allowed).
2. **Given** a pocket with `available_balance = 100`, **When** a debit of 101 is requested, **Then** HTTP 422 `INSUFFICIENT_BALANCE` is returned; no ledger entry is created; no hold is placed.
3. **Given** a pocket with `available_balance = 0`, **When** any debit is requested, **Then** HTTP 422 `INSUFFICIENT_BALANCE` is returned.
4. **Given** two concurrent debit requests each for 60 BWP against `available_balance = 100`, **When** both are processed simultaneously, **Then** exactly one succeeds and one is rejected with `INSUFFICIENT_BALANCE`; final `available_balance = 40`, never negative.
5. **Given** the balance check passes but the ledger posting fails (transactional rollback), **When** the failure occurs, **Then** the hold is also rolled back; the available balance returns to its pre-request value.

---

### US-WALLET-013 — Freeze Wallet (P0)

**Actor**: Admin / Compliance Officer

**Story**: As a Compliance Officer or Admin, I want to freeze a wallet so that all transaction activity is immediately suspended pending investigation.

**Independent Test**: Freeze an active wallet → `status = frozen`; attempt any debit against the wallet → rejected with `WALLET_FROZEN`; `wallet_status_history` has a new entry; audit event in `platform.audit_events`.

**Acceptance Scenarios**:

1. **Given** a wallet in `active` status, **When** an authorized Compliance Officer calls `POST /v1/wallets/{id}/freeze` with a reason, **Then** the wallet transitions to `frozen`; a `WalletStatusHistory` entry is recorded; `wallet.frozen` event is emitted; an audit event is written to `platform.audit_events`.
2. **Given** a frozen wallet, **When** freeze is requested again (same `Idempotency-Key`), **Then** HTTP 200 is returned (idempotent); no duplicate status-history entry is created.
3. **Given** an unauthorized actor (Customer) attempts to freeze a wallet, **When** the request is processed, **Then** HTTP 403 is returned.
4. **Given** a closed wallet, **When** freeze is attempted, **Then** HTTP 422 `INVALID_WALLET_TRANSITION` is returned.
5. **Given** a wallet is frozen, **When** `WalletModuleContract::isActive(wallet_id)` is called, **Then** `false` is returned.

---

### US-WALLET-014 — Unfreeze Wallet (P0)

**Actor**: Admin / Compliance Officer

**Story**: As a Compliance Officer or Admin, I want to unfreeze a wallet so that legitimate customers are not permanently blocked after an investigation concludes.

**Independent Test**: Unfreeze a frozen wallet → `status = active`; subsequent valid debit succeeds; audit event recorded.

**Acceptance Scenarios**:

1. **Given** a wallet in `frozen` status, **When** an authorized Admin calls `POST /v1/wallets/{id}/unfreeze` with a reason, **Then** the wallet transitions to `active`; a status history entry is recorded; `wallet.unfrozen` event is emitted; an audit event is written.
2. **Given** an active wallet, **When** unfreeze is requested, **Then** HTTP 422 `INVALID_WALLET_TRANSITION` is returned.
3. **Given** an unauthorized actor attempts to unfreeze, **When** the request is processed, **Then** HTTP 403 is returned.
4. **Given** a wallet is unfrozen, **When** `WalletModuleContract::isActive(wallet_id)` is called, **Then** `true` is returned.
5. **Given** a freeze/unfreeze cycle, **When** wallet status history is queried, **Then** both transitions are recorded in chronological order with actor, reason, and timestamp.

---

### US-WALLET-015 — Close Wallet (P1)

**Actor**: Customer / authorized financial service

**Story**: As a Customer or Admin, I want to close a wallet that is no longer needed so that it is permanently decommissioned and no further transactions can occur.

**Independent Test**: Attempt to close a wallet with non-zero balance → HTTP 422 `WALLET_HAS_BALANCE`; close a zero-balance wallet → `status = closed`; subsequent debit returns `WALLET_CLOSED`.

**Acceptance Scenarios**:

1. **Given** a wallet with all pockets at zero balance, **When** an authorized actor calls `POST /v1/wallets/{id}/close` with a reason, **Then** the wallet transitions to `closed`; all pockets transition to `disabled`; a status history entry is recorded; `wallet.closed` event is emitted; an audit event is written.
2. **Given** a wallet with any pocket having non-zero balance, **When** close is requested, **Then** HTTP 422 `WALLET_HAS_BALANCE` is returned; no state change occurs (see TBD-E06-003 for admin override).
3. **Given** a closed wallet, **When** any transaction is attempted, **Then** HTTP 422 `WALLET_CLOSED` is returned.
4. **Given** a closed wallet, **When** close is requested again, **Then** HTTP 200 is returned (idempotent; already closed).
5. **Given** a closed wallet, **When** `WalletModuleContract::isActive(wallet_id)` is called, **Then** `false` is returned.

---

### US-WALLET-016 — Prevent Transactions on Frozen Wallet (P0)

**Actor**: Customer / authorized financial service

**Story**: As a financial integrity service, I want all transaction operations against a frozen wallet to be immediately rejected so that the freeze control is reliably enforced at every entry point.

**Independent Test**: With a frozen wallet, attempt debit, credit, and hold placement → all return `WALLET_FROZEN`; no ledger entries are created; balance inquiry returns the current balance successfully.

**Acceptance Scenarios**:

1. **Given** a frozen wallet, **When** a debit is requested, **Then** HTTP 422 `WALLET_FROZEN` is returned before any balance check or ledger posting.
2. **Given** a frozen wallet, **When** a credit is requested (e.g., incoming transfer), **Then** HTTP 422 `WALLET_FROZEN` is returned (both inbound and outbound are blocked).
3. **Given** a frozen wallet, **When** a hold placement is attempted, **Then** HTTP 422 `WALLET_FROZEN` is returned; `held_amount` is unchanged.
4. **Given** a frozen wallet, **When** balance inquiry (read-only) is requested by the owner, **Then** the balance is returned; read operations are not blocked by freeze.
5. **Given** a `suspended` or `restricted` wallet, **When** a debit is requested, **Then** the appropriate `WALLET_NOT_TRANSACTABLE` error is returned reflecting the current status; all non-active, non-restricted-inbound states block debits.

---

### US-WALLET-017 — Maintain Wallet Status History (P0)

**Actor**: Customer / authorized financial service

**Story**: As an Admin or Customer, I want to view the complete history of wallet status transitions so that I have a full audit trail of all lifecycle events.

**Independent Test**: After creation → activation → freeze → unfreeze, query `GET /v1/wallets/{id}/status-history` → 4 entries in chronological order with correct `from_status`, `to_status`, actor, and timestamp.

**Acceptance Scenarios**:

1. **Given** a wallet that has gone through creation, activation, freeze, and unfreeze, **When** status history is queried, **Then** 4 entries are returned with `from_status`, `to_status`, `reason`, `actor_id`, and `occurred_at` for each.
2. **Given** a status history entry is written, **When** subsequent queries are made, **Then** the entry is never modified or deleted; it is immutable.
3. **Given** a status transition occurs, **When** `wallet_status_history` is queried, **Then** the entry includes the correct `actor_id` for the user or system process that triggered the transition.
4. **Given** pagination parameters are provided, **When** status history is retrieved, **Then** results are cursor-paginated in chronological order (ascending `occurred_at`).
5. **Given** an unauthorized actor queries status history of another user's wallet, **When** the request is processed, **Then** HTTP 404 is returned.

---

## 7. Functional Requirements

### Wallet Creation and Identity

**FR-E06-001** — The system SHALL automatically provision a wallet when a `kyc.case.approved` domain event is received for a user who does not already have a non-closed wallet.

**FR-E06-002** — Wallet auto-provisioning SHALL be idempotent; duplicate event deliveries for the same user SHALL be detected and silently skipped without creating duplicate wallets.

**FR-E06-003** — A wallet SHALL have a system UUID v7 identifier (`id`) and a unique human-readable wallet reference (`wallet_reference`); the reference format is TBD-E06-007 and MUST be stored as a distinct, indexed column.

**FR-E06-004** — A user MUST NOT have more than one active (non-`closed`) wallet; attempting to create a second wallet for the same user SHALL return HTTP 409 `WALLET_ALREADY_EXISTS`.

**FR-E06-005** — Each wallet SHALL be associated with exactly one `owner_id` (user UUID from E01/E04); this association is immutable after creation.

**FR-E06-006** — Wallet creation SHALL atomically create the wallet record, the default BWP currency pocket, and the first `wallet_status_history` entry (with `from_status = null`, `to_status = pending`) in a single database transaction.

**FR-E06-007** — Wallet creation via the API (`POST /v1/wallets`) SHALL require an `Idempotency-Key` header; duplicate calls with the same key SHALL return the original response without creating a second wallet.

### Currency Pockets

**FR-E06-008** — A wallet SHALL support multiple currency pockets, with at most one pocket per ISO 4217 currency code per wallet.

**FR-E06-009** — Supported currencies at baseline are: `BWP` (primary, created automatically with wallet), `USD`, `EUR`, `ZAR`, `CNY`, `USDT`. Additional currencies MAY be enabled through the `currencies` configuration table without code changes.

**FR-E06-010** — Currency metadata (ISO code, decimal precision, display name, enabled flag) SHALL be stored in a `currencies` configuration table, not hard-coded in application logic.

**FR-E06-011** — USDT precision is TBD-E06-001; the `precision` column in `currencies` MUST be populated from configuration; no USDT-specific precision constant may appear in code.

**FR-E06-012** — Adding a currency pocket SHALL require that the wallet is in `active` or `pending` status and that the currency has `is_enabled = true` in the `currencies` table; otherwise the operation is rejected.

**FR-E06-013** — Creating a pocket SHALL be idempotent: a second `POST /v1/wallets/{id}/pockets` for the same currency on the same wallet SHALL return HTTP 409 `POCKET_ALREADY_EXISTS`.

**FR-E06-014** — Each currency pocket SHALL have its own `ledger_account_reference` (an opaque reference to the ledger account owned by E07); this reference is a UUID v7 generated by E06 at pocket creation time and stored on the `currency_pockets` row. E07 uses this pre-assigned reference when registering ledger entries for the pocket; E07 does not need to be available at pocket creation for this reference to be assigned.

**FR-E06-015** — A pocket MAY be in `active` or `disabled` status; only `active` pockets can participate in transactions.

### Balance Architecture

**FR-E06-016** — Wallet balances MUST NOT be stored as mutable fields directly updated by transaction processing; all balances SHALL be derived from the authoritative ledger (E07) via the `LedgerServiceContract` interface.

**FR-E06-017** — **Available balance** is defined as: `available_balance = max(0, ledger_balance − held_amount)`. It MUST NOT be negative.

**FR-E06-018** — **Ledger balance** is the sum of all settled credit entries minus all settled debit entries for the pocket's ledger account, as returned by `LedgerServiceContract::getBalance(ledger_account_reference)`.

**FR-E06-019** — **Held/reserved amount** is the sum of all `WalletHold` records in `active` status for the pocket; holds are created by transaction initiation and released on settlement, cancellation, or expiry.

**FR-E06-020** — **Pending balance** is the sum of amounts in transactions that are in `initiated` or `pending_confirmation` states for the pocket and have not yet posted to the ledger. Until E11 (Transaction Management) delivers a `TransactionServiceContract` interface, `pending_balance` SHALL return `0`; the field is reserved in the API response schema and MUST NOT be removed.

**FR-E06-021** — Balance inquiry responses SHALL include: `currency_code`, `precision`, `ledger_balance`, `held_amount`, `available_balance`, `pending_balance`, `as_of_timestamp`, and the wallet `status`. `as_of_timestamp` reflects the moment the balance snapshot was taken (UTC, RFC 3339); it does not guarantee real-time settlement and MUST be included even when sourced from a short-lived cache.

**FR-E06-022** — Balance amounts in API responses SHALL be expressed as integer values in the currency's minor unit; a separate `precision` field indicates the number of decimal places for display.

### Balance Enforcement

**FR-E06-023** — Before posting any debit or placing a hold, the system SHALL verify `debit_amount <= available_balance` in an atomic, locked read-modify-write operation.

**FR-E06-024** — If the balance check fails, the operation SHALL return HTTP 422 `INSUFFICIENT_BALANCE`; no ledger entry SHALL be created; no hold SHALL be placed.

**FR-E06-025** — Balance enforcement SHALL use `SELECT FOR UPDATE` on the pocket record or an equivalent serializable transaction scope to prevent race conditions under concurrent debit requests.

**FR-E06-026** — Daily, weekly, monthly, and per-transaction limit checks SHALL be performed before ledger posting; a transaction exceeding any applicable limit SHALL return HTTP 422 `TRANSACTION_LIMIT_EXCEEDED`.

### Wallet Limits

**FR-E06-027** — Wallet limits are persisted in the `wallet_limits` table per wallet, per pocket (currency), per limit type. Limit types: `daily_transaction_amount`, `weekly_transaction_amount`, `monthly_transaction_amount`, `single_transaction_amount`, `max_balance`.

**FR-E06-028** — All limit seed values are TBD-E06-004; they SHALL be seeded with `is_tbd = true` in the configuration table, consistent with the E05 TBD pattern.

**FR-E06-029** — Limit enforcement is currency-pocket scoped; cross-currency aggregate limits are out of scope (E29).

**FR-E06-030** — Limit updates SHALL be audited; every change to `wallet_limits` SHALL produce a `platform.audit_events` record with old values, new values, actor, and `correlation_id`.

### Wallet Status and Lifecycle

**FR-E06-031** — Valid wallet lifecycle states are: `pending`, `active`, `frozen`, `suspended`, `restricted`, `closed`.

**FR-E06-032** — Valid state transitions are:
- `pending` → `active` (activation)
- `active` → `frozen` (freeze)
- `frozen` → `active` (unfreeze)
- `active` → `suspended` (admin/system)
- `suspended` → `active` (admin/system)
- `active` → `restricted` (admin/system)
- `restricted` → `active` (admin/system)
- `pending`, `active`, `frozen`, `suspended`, `restricted` → `closed` (close; subject to zero-balance guard per TBD-E06-003)
- All other transitions are invalid and SHALL return HTTP 422 `INVALID_WALLET_TRANSITION`.

**FR-E06-033** — A transaction against a wallet in `frozen`, `suspended`, `restricted` (outbound debits only; see FR-E06-034), or `closed` status SHALL be rejected before any balance check or ledger interaction.

**FR-E06-034** — A `restricted` wallet permits incoming credits but blocks outbound debits; this state supports regulatory restriction scenarios.

**FR-E06-035** — Every wallet status transition SHALL produce an immutable `wallet_status_history` entry with: `wallet_id`, `from_status`, `to_status`, `reason` (required for privileged transitions), `actor_id`, `occurred_at`.

**FR-E06-036** — Wallet closure SHALL verify that all pockets have `available_balance = 0` and `held_amount = 0`; closure against a non-zero pocket returns HTTP 422 `WALLET_HAS_BALANCE` (see TBD-E06-003 for admin override path).

### Events and Observability

**FR-E06-037** — All wallet mutations SHALL emit domain events via the `OutboxEventPublisher` inside the same database transaction as the state mutation, per Platform Foundation Principle 9.

**FR-E06-038** — Wallet domain events (outbox; PascalCase dot notation per platform convention): `Wallet.Created`, `Wallet.Activated`, `Wallet.Pocket.Added`, `Wallet.Status.Changed`, `Wallet.Frozen`, `Wallet.Unfrozen`, `Wallet.Closed`, `Wallet.Limits.Configured`, `Wallet.Hold.Expired`. Note: audit event `action` slugs in Section 15.1 use lowercase dot notation for the `platform.audit_events.action` field — these are distinct from outbox event names.

**FR-E06-039** — Event payloads SHALL include: `event_id` (UUID v7), `aggregate_id` (wallet_id), `actor_id`, `correlation_id`, `occurred_at` (UTC ISO-8601), `schema_version`.

**FR-E06-040** — Metrics SHALL be emitted for: `wallet.created`, `wallet.frozen`, `wallet.closed`, `wallet.balance.insufficient`, `wallet.limit.exceeded`, `wallet.idor.blocked`.

### Permissions

**FR-E06-041** — The following permissions SHALL be seeded for E06: `wallet.create`, `wallet.read`, `wallet.read.any`, `wallet.pocket.create`, `wallet.limits.manage`, `wallet.freeze`, `wallet.unfreeze`, `wallet.close`, `wallet.status.manage`, `wallet.balance.read`.

**FR-E06-042** — Customer and Agent roles SHALL receive: `wallet.read`, `wallet.pocket.create`, `wallet.balance.read`.

**FR-E06-043** — Admin role SHALL receive all 10 wallet permissions.

**FR-E06-044** — `wallet.freeze`, `wallet.unfreeze`, `wallet.close`, and `wallet.status.manage` are privileged permissions not assigned to Customer or Agent.

**FR-E06-045** — Resource ownership SHALL be validated server-side on every wallet endpoint; a Customer requesting another Customer's wallet or pocket returns HTTP 404.

### Cross-Module Contract

**FR-E06-046** — `WalletModuleContract` SHALL be expanded to expose: `isActive(string $walletId): bool`, `ownerActorId(string $walletId): ?string`, `currency(string $walletId): CurrencyContract`, `pocketExists(string $walletId, string $currencyCode): bool`, `availableBalance(string $walletId, string $currencyCode): int`. The `currency()` method returns the wallet's primary/home currency (BWP by default); for currency-specific pocket queries use `pocketExists()`.

**FR-E06-047** — The `NullWalletModule` SHALL be updated to implement all new methods and throw `ModuleNotImplementedException`; downstream epics MUST NOT depend directly on Wallet domain models.

**FR-E06-048** — A scheduled Artisan command `wallet:expire-holds` SHALL be registered and run at a cadence TBD-E06-009; it SHALL set all `WalletHold` records where `expires_at <= NOW()` and `status = 'active'` to `status = 'expired'`, recording `expired_at` and a `correlation_id`. The command MUST be idempotent and safe to run concurrently. Each expiry event SHALL emit a `Wallet.Hold.Expired` domain event via the outbox.

**FR-E06-049** — An architecture-integrity test SHALL assert that no file outside `app/Modules/Wallet/` imports or directly references Wallet Eloquent models (`Wallet`, `CurrencyPocket`, `WalletHold`, `WalletLimit`, `WalletStatusHistory`); any cross-module dependency MUST go through `WalletModuleContract`. This test MUST be placed in `tests/Architecture/Modules/WalletBoundaryTest.php` and run as part of the CI pipeline.

---

## 8. Domain Model

### 8.1 Entity: Wallet

| Field | Type | Description |
|---|---|---|
| `id` | UUID v7 | System identifier |
| `wallet_reference` | varchar(32) | Human-readable unique reference (format TBD-E06-007) |
| `owner_id` | UUID | User identifier from E01 |
| `status` | enum | `pending`, `active`, `frozen`, `suspended`, `restricted`, `closed` |
| `idempotency_key` | varchar(255) | Creation idempotency key |
| `created_at` | timestamptz | UTC |
| `updated_at` | timestamptz | UTC |
| `created_by` | UUID | Actor ID of creator |
| `version` | int | Optimistic lock version |

**Constraints**: `owner_id` is unique when `status != closed`; `wallet_reference` is globally unique.

### 8.2 Entity: CurrencyPocket

| Field | Type | Description |
|---|---|---|
| `id` | UUID v7 | System identifier |
| `wallet_id` | UUID | Parent wallet |
| `currency_code` | varchar(10) | ISO 4217 code |
| `ledger_account_reference` | varchar(255) | Opaque reference to E07 ledger account |
| `status` | enum | `active`, `disabled` |
| `created_at` | timestamptz | UTC |
| `updated_at` | timestamptz | UTC |
| `created_by` | UUID | Actor ID |

**Constraints**: (`wallet_id`, `currency_code`) is unique.

### 8.3 Entity: WalletHold

| Field | Type | Description |
|---|---|---|
| `id` | UUID v7 | System identifier |
| `pocket_id` | UUID | Currency pocket |
| `amount` | bigint | Hold amount in minor currency unit |
| `currency_code` | varchar(10) | Matches pocket currency |
| `status` | enum | `active`, `released`, `expired` |
| `transaction_reference` | varchar(255) | Reference to initiating transaction |
| `description` | text | Human-readable reason |
| `created_at` | timestamptz | UTC |
| `released_at` | timestamptz | UTC when released |
| `expires_at` | timestamptz | UTC optional hold expiry |
| `expired_at` | timestamptz | UTC when expired by `wallet:expire-holds` job |
| `created_by` | UUID | Actor ID |

### 8.4 Entity: WalletLimit

| Field | Type | Description |
|---|---|---|
| `id` | UUID v7 | System identifier |
| `wallet_id` | UUID | Parent wallet |
| `currency_code` | varchar(10) | Pocket currency |
| `limit_type` | enum | `daily_transaction_amount`, `weekly_transaction_amount`, `monthly_transaction_amount`, `single_transaction_amount`, `max_balance` |
| `limit_value` | bigint | Minor currency unit; NULL = unlimited |
| `is_tbd` | bool | True when seed value is pending business decision |
| `effective_from` | timestamptz | UTC |
| `created_by` | UUID | Actor ID |
| `updated_by` | UUID | Actor ID |
| `created_at` | timestamptz | UTC |
| `updated_at` | timestamptz | UTC |

### 8.5 Entity: WalletStatusHistory

| Field | Type | Description |
|---|---|---|
| `id` | UUID v7 | System identifier |
| `wallet_id` | UUID | Parent wallet |
| `from_status` | enum/null | Status before transition (null for creation) |
| `to_status` | enum | Status after transition |
| `reason` | text | Required for privileged transitions |
| `actor_id` | UUID | Actor who triggered the transition |
| `correlation_id` | varchar(255) | Request correlation ID |
| `occurred_at` | timestamptz | UTC |

**Constraint**: This table is append-only; no row may be updated or deleted.

### 8.6 Entity: Currency (configuration)

| Field | Type | Description |
|---|---|---|
| `code` | varchar(10) | ISO 4217 code (PK) |
| `name` | varchar(100) | Display name |
| `precision` | smallint | Decimal places; nullable for TBD currencies |
| `is_enabled` | bool | Whether pockets can be created |
| `is_primary` | bool | True for BWP |
| `created_at` | timestamptz | UTC |
| `updated_at` | timestamptz | UTC |

**Seed data**: BWP (precision=2, enabled, primary), USD (precision=2, enabled), EUR (precision=2, enabled), ZAR (precision=2, enabled), CNY (precision=2, enabled), USDT (precision=TBD-E06-001, enabled).

---

## 9. Wallet Lifecycle State Machine

```
              [kyc.case.approved / POST /v1/wallets]
                             │
                             ▼
                          pending ──────────────────────────┐
                             │                              │
                     [activate]                       [close (admin)]
                             │                              │
                             ▼                              │
              ┌──────────── active ──────────────┐         │
              │              │         │         │         │
       [freeze]        [suspend]  [restrict]  [close]      │
              │              │         │         │         │
              ▼              ▼         ▼         │         │
           frozen        suspended  restricted   │         │
              │              │         │         │         │
      [unfreeze]    [activate]  [activate]       │         │
              │              │         │         │         │
              └──────────────┴─────────┘         │         │
                             │              [close]  [close]
                             └──────────────────┴─────────┘
                                                 │
                                              closed
                                            (terminal)
```

**State rules**:
- `closed` is terminal; no transition out of closed is permitted.
- `frozen` blocks all financial write operations; read access is permitted.
- `restricted` permits inbound credits; blocks outbound debits.
- `suspended` blocks all financial operations (admin/operational hold).
- `pending` wallets cannot receive or initiate transactions.
- Closure from any state requires zero available balance and zero held amount per all pockets (subject to TBD-E06-003).

---

## 10. Currency Pocket Architecture

### 10.1 Hierarchy

```
Wallet (one per user)
  └── CurrencyPocket (one per supported currency)
        └── ledger_account_reference → LedgerAccount (owned by E07)
              └── Journal / LedgerEntry (owned by E07)
```

### 10.2 Pocket Creation Rules

- BWP pocket is created automatically with every new wallet.
- Additional pockets (USD, EUR, ZAR, CNY, USDT) are created explicitly via `POST /v1/wallets/{id}/pockets`.
- Whether all 6 pockets are created eagerly at wallet creation or lazily on demand is TBD-E06-008; the default behavior is lazy (BWP only at creation).
- A pocket cannot be created for a currency with `is_enabled = false`.
- Pockets are never deleted; they are set to `disabled` when not needed.

### 10.3 Currency Precision

Each currency's precision is stored in the `currencies` table and referenced at every balance calculation and display operation. Database amounts are stored as integer **minor units** (e.g., BWP thebe = 1/100 BWP). API responses expose both the integer amount and the precision so clients can render correctly.

USDT precision is TBD-E06-001. The system MUST accept whatever precision is configured; no code path may hard-code USDT's decimal places.

### 10.4 FX and Cross-Currency

FX conversion, exchange rate management, and cross-pocket transfers are out of scope for E06. These are owned by E29. E06 provides the pocket structure upon which E29 will build.

---

## 11. Balance Architecture

### 11.1 Authoritative Balance Source

The ledger (E07) is the sole source of truth for settled balances. E06 MUST NOT maintain a separate `current_balance` field on `CurrencyPocket` that is directly mutated by transactions; this would violate constitution Principle 4.

The `LedgerServiceContract` interface (defined by E07) exposes a method to retrieve the computed balance from all settled ledger entries for a given `ledger_account_reference`.

### 11.2 Balance Components

| Component | Source | Formula |
|---|---|---|
| `ledger_balance` | E07 LedgerServiceContract | SUM(credits) − SUM(debits) for the pocket's ledger account |
| `held_amount` | `wallet.wallet_holds` table | SUM(amount) WHERE status = 'active' AND pocket_id = ? |
| `available_balance` | Computed | max(0, ledger_balance − held_amount) |
| `pending_balance` | Transaction tracking (E11) | SUM of in-flight transaction amounts |

### 11.3 Concurrency and Locking

For any debit request against a pocket:

1. Begin database transaction.
2. `SELECT FOR UPDATE` on the `currency_pockets` row to serialize concurrent debits.
3. Retrieve `ledger_balance` via `LedgerServiceContract` within the transaction scope.
4. Compute `held_amount` from `wallet_holds` within the same transaction.
5. Verify `debit_amount <= max(0, ledger_balance − held_amount)`.
6. If check passes: insert hold record; commit transaction; submit to ledger via E07.
7. If check fails: rollback; return `INSUFFICIENT_BALANCE`.

This ensures two concurrent debits cannot both succeed against insufficient available balance.

**Hold placement interface**: E08 (Transaction Initiation) and E09 (Payment Processing) MUST NOT write hold records directly; they interact with holds exclusively via `WalletHoldService::place(string $pocketId, int $amount, string $correlationId, ?\DateTimeImmutable $expiresAt): WalletHold`. This service is an internal E06 application service, not a public module contract. The `WalletHoldService` enforces the lock-then-check sequence above.

### 11.4 Hold Lifecycle

```
TRANSACTION INITIATED     → WalletHold created (status = active)
TRANSACTION COMPLETED     → WalletHold released (status = released, released_at set)
TRANSACTION FAILED        → WalletHold released (status = released)
TRANSACTION CANCELLED     → WalletHold released (status = released)
HOLD EXPIRES (scheduled)  → WalletHold set to expired (wallet:expire-holds job)
```

Hold expiry prevents permanently locked funds from transactions that never resolve.

---

## 12. Security Requirements

**SR-E06-001** — All wallet endpoints MUST require a valid Bearer token; unauthenticated requests return HTTP 401.

**SR-E06-002** — Authorization is server-side, enforced by `identity.require-permission` middleware from E04; client-provided authorization hints are never trusted.

**SR-E06-003** — Wallet ownership is validated on every read and write operation; a Customer requesting another user's wallet or pocket receives HTTP 404 (not 403) to prevent information disclosure via IDOR.

**SR-E06-004** — Service-to-service wallet creation (triggered by event processing) uses a service identity credential; the `created_by` field records the service actor ID.

**SR-E06-005** — Freeze, unfreeze, and close operations require privileged permissions (`wallet.freeze`, `wallet.unfreeze`, `wallet.close`); these operations MAY additionally require step-up authentication when TBD-E06-002 is resolved.

**SR-E06-006** — All state-changing wallet operations MUST include an `Idempotency-Key` header; replay protection is enforced via the Platform IdempotencyService (PostgreSQL-authoritative, Redis read-through per existing architecture decision OKI-ARCH-2026-003).

**SR-E06-007** — Input validation: `wallet_id` and `pocket_id` must be valid UUID strings; `currency_code` must be from the enabled currencies table; amount fields must be non-negative integers within the currency's representable range.

**SR-E06-008** — All privileged wallet operations (freeze, unfreeze, close, limits change, status change) MUST be recorded in `platform.audit_events` with: `action`, `actor_id`, `resource_type = wallet`, `resource_id`, `metadata` (JSON with relevant context), `correlation_id`, `occurred_at`.

**SR-E06-009** — Balance enforcement MUST use row-level locking (`SELECT FOR UPDATE`) on the `currency_pockets` row to prevent negative balances under concurrent debit operations.

**SR-E06-010** — Rate limiting SHALL be applied to wallet creation and balance inquiry endpoints; rates are TBD-E06-005 and managed via platform configuration.

**SR-E06-011** — Event payloads MUST NOT include sensitive PII; only `owner_id` (UUID), `wallet_id`, and metadata necessary for routing are included.

**SR-E06-012** — Wallet closure MUST verify zero balance for all pockets before proceeding; a non-zero-balance wallet SHALL NOT be silently closed (see TBD-E06-003 for explicit admin override path).

**SR-E06-013** — Security test coverage MUST include IDOR tests for all endpoints accepting `wallet_id` or `pocket_id`; cross-user access is tested in `tests/Security/Modules/Wallet/`.

**SR-E06-014** — `WalletModuleContract` exposes no internal domain model objects across module boundaries; only primitive types or purpose-built value objects are returned.

**SR-E06-015** — `wallet_status_history` is append-only; no application code path may update or delete existing history entries; this is enforced at the repository layer.

---

## 13. Idempotency Requirements

All state-changing wallet operations require an `Idempotency-Key` header per constitution Principle 8. The Platform `IdempotencyService` (PostgreSQL-authoritative, Redis read-through) is the implementation.

| Operation | Endpoint | Idempotency-Key Required |
|---|---|---|
| Create wallet | `POST /v1/wallets` | Yes |
| Add pocket | `POST /v1/wallets/{id}/pockets` | Yes |
| Set wallet status | `PATCH /v1/wallets/{id}/status` | Yes |
| Freeze wallet | `POST /v1/wallets/{id}/freeze` | Yes |
| Unfreeze wallet | `POST /v1/wallets/{id}/unfreeze` | Yes |
| Close wallet | `POST /v1/wallets/{id}/close` | Yes |
| Configure limits | `PUT /v1/wallets/{id}/limits` | Yes |
| Balance inquiry | `GET /v1/wallets/{id}/pockets/{code}/balance` | No (read-only) |
| Status history | `GET /v1/wallets/{id}/status-history` | No (read-only) |

**Idempotency behavior**:
- A second request with the same `Idempotency-Key` from the same authenticated actor returns the cached HTTP response.
- Idempotency keys are scoped to actor; keys from different actors for the same wallet do not collide.
- Idempotency records expire after the configured TTL (managed via platform configuration); after expiry a new request with the same key is treated as a new operation.

---

## 14. Concurrency Requirements

Financial operations against the same currency pocket must be serializable.

**CC-E06-001** — Two simultaneous debit requests against the same pocket where the combined amount exceeds available balance MUST result in exactly one success and one `INSUFFICIENT_BALANCE` rejection; negative balance is never permitted.

**CC-E06-002** — Simultaneous hold placements for the same pocket MUST serialize correctly; `SELECT FOR UPDATE` on the pocket row serializes hold computation.

**CC-E06-003** — A freeze operation racing with a debit operation MUST result in a consistent state: either the debit completes (freeze arrives after) or the debit is rejected with `WALLET_FROZEN` (freeze arrives first); neither may leave an inconsistent intermediate state.

**CC-E06-004** — Duplicate `kyc.case.approved` events delivered simultaneously for the same user MUST result in exactly one wallet, not two.

**CC-E06-005** — Concurrency tests MUST be present in `tests/Concurrency/Modules/Wallet/` covering at minimum: simultaneous debits exceeding balance, simultaneous pocket creation for the same currency, and simultaneous freeze + debit.

---

## 15. Audit and Observability

### 15.1 Audit Events

All state-changing wallet operations write to `platform.audit_events`. The `action` field uses dot-notation lowercase slugs:

| Action | Trigger |
|---|---|
| `wallet.created` | Wallet provisioned |
| `wallet.activated` | Wallet transitioned to active |
| `wallet.pocket.added` | Currency pocket created |
| `wallet.status.changed` | Any status transition |
| `wallet.frozen` | Wallet frozen |
| `wallet.unfrozen` | Wallet unfrozen |
| `wallet.closed` | Wallet closed |
| `wallet.limits.configured` | Wallet limits updated |
| `wallet.balance.insufficient` | Debit rejected for insufficient funds |
| `wallet.limit.exceeded` | Debit rejected for limit violation |
| `wallet.idor.blocked` | Cross-user access attempt blocked |

### 15.2 Metrics

| Metric | Description |
|---|---|
| `wallet.created` | Counter: wallets provisioned |
| `wallet.frozen` | Counter: wallets frozen |
| `wallet.closed` | Counter: wallets closed |
| `wallet.balance.insufficient` | Counter: overdraft prevention events |
| `wallet.limit.exceeded` | Counter: limit enforcement events |
| `wallet.idor.blocked` | Counter: IDOR prevention events |
| `wallet.balance.query.duration` | Histogram: balance query latency |

### 15.3 Structured Logging

All wallet service operations log at `info` level with: `wallet_id`, `operation`, `correlation_id`, `actor_id`, `outcome` (success/failure/idempotent_replay).

### 15.4 Correlation IDs

`X-Correlation-ID` headers are propagated from ingress through all wallet service operations; event payloads carry `correlation_id`.

---

## 16. API Requirements

All wallet endpoints follow platform-wide API conventions (constitution Principle 8):
- Base path: `/v1/wallets`
- JSON request/response bodies
- UTC timestamps in ISO-8601 format
- UUID v7 identifiers
- Standard response envelope: `{ success: bool, data: {...}, error: {...|null}, correlation_id: string }`
- `X-Correlation-ID` request/response header
- `Idempotency-Key` header on all mutation endpoints

### 16.1 Endpoint Summary

| Method | Path | Description | Required Permission |
|---|---|---|---|
| `POST` | `/v1/wallets` | Create wallet | `wallet.create` |
| `GET` | `/v1/wallets/my` | Get authenticated user's wallet | `wallet.read` |
| `GET` | `/v1/wallets/{id}` | Get wallet (own or any) | `wallet.read` / `wallet.read.any` |
| `PATCH` | `/v1/wallets/{id}/status` | Set wallet status | `wallet.status.manage` |
| `POST` | `/v1/wallets/{id}/freeze` | Freeze wallet | `wallet.freeze` |
| `POST` | `/v1/wallets/{id}/unfreeze` | Unfreeze wallet | `wallet.unfreeze` |
| `POST` | `/v1/wallets/{id}/close` | Close wallet | `wallet.close` |
| `GET` | `/v1/wallets/{id}/limits` | View wallet limits | `wallet.read` |
| `PUT` | `/v1/wallets/{id}/limits` | Configure wallet limits | `wallet.limits.manage` |
| `GET` | `/v1/wallets/{id}/pockets` | List pockets | `wallet.read` |
| `POST` | `/v1/wallets/{id}/pockets` | Add currency pocket | `wallet.pocket.create` |
| `GET` | `/v1/wallets/{id}/pockets/{code}/balance` | Get pocket balance | `wallet.balance.read` |
| `GET` | `/v1/wallets/{id}/status-history` | Get status history | `wallet.read` |

### 16.2 Error Codes

| Code | HTTP | Description |
|---|---|---|
| `WALLET_ALREADY_EXISTS` | 409 | User already has an active wallet |
| `WALLET_NOT_FOUND` | 404 | Wallet not found or not owned by requester |
| `WALLET_FROZEN` | 422 | Wallet is frozen; transaction blocked |
| `WALLET_CLOSED` | 422 | Wallet is closed; operation not permitted |
| `WALLET_NOT_TRANSACTABLE` | 422 | Wallet in non-transactable status |
| `WALLET_HAS_BALANCE` | 422 | Closure blocked by non-zero pocket balance |
| `WALLET_NOT_EDITABLE` | 422 | Operation not allowed in current wallet status |
| `INVALID_WALLET_TRANSITION` | 422 | Invalid status transition requested |
| `POCKET_ALREADY_EXISTS` | 409 | Currency pocket already exists on this wallet |
| `UNSUPPORTED_CURRENCY` | 422 | Currency code not in supported currencies list |
| `CURRENCY_NOT_AVAILABLE` | 422 | Currency is disabled |
| `INSUFFICIENT_BALANCE` | 422 | Debit amount exceeds available balance |
| `TRANSACTION_LIMIT_EXCEEDED` | 422 | Transaction exceeds a configured wallet limit |

### 16.3 Pagination

- `GET /v1/wallets/{id}/status-history`: cursor-based pagination; results ordered ascending by `occurred_at`.
- `GET /v1/wallets/{id}/pockets`: returns all pockets (bounded by number of supported currencies; no pagination required).

---

## 17. UI/UX Requirements

### 17.1 Consumer Flutter App

**Wallet Overview Screen**:
- Displays `wallet_reference`, overall wallet status, and list of currency pockets with `available_balance` per pocket.
- Clearly distinguishes wallet states: frozen (orange/red badge + icon), restricted (informational badge), closed (grayed-out).
- Empty state when wallet has just been created and no transactions exist.
- Loading (skeleton) and error (retry prompt) states for balance fetch.
- Color alone MUST NOT be the sole indicator of wallet state; icon or text label is also required (accessibility).

**Currency Pocket Screen**:
- Shows `available_balance`, `held_amount`, `pending_balance` for the selected pocket.
- Displays currency code, precision, and a list of active holds (hold_id, amount, description).
- Zero-balance state is shown without error messaging.

**Wallet Status States** (all must be visually distinguishable per constitution Principle 10):
- `active`: default, no additional indicator.
- `frozen`: prominent badge labeled "Account Frozen".
- `restricted`: informational badge labeled "Limited Access".
- `suspended`: badge labeled "Account Suspended".
- `closed`: grayed-out, labeled "Account Closed".

**Accessibility**:
- Balance `Semantics` widgets include `label` with currency code (e.g., "200 Botswana Pula available").
- All status badges use both color and text/icon to convey state.
- Focus order follows reading order on balance screen.

### 17.2 Agent Flutter App

**Customer Wallet Lookup** (requires `wallet.read.any`):
- Lookup by `wallet_reference` or `owner_id`.
- Read-only view: balance per pocket, wallet status.
- Frozen/restricted states shown with appropriate indicator.
- No transaction initiation from this screen (E08/E09 scope).

**Agent Own Wallet**:
- Same as Consumer Wallet Overview.

### 17.3 Admin Next.js Platform

**Wallet Management List**:
- Paginated table: `wallet_reference`, owner name, status, created_at, pocket count.
- Filters: status, owner name/email, wallet_reference, date range.
- Row actions: View, Freeze, Unfreeze, Close (permission-gated; buttons hidden if unauthorized).

**Wallet Detail Page**:
- Header: `wallet_reference`, owner, status, created_at, last updated.
- Tabs: Pockets, Limits, Status History, Audit Trail.
- Pockets tab: per-pocket table with currency, ledger_balance, held_amount, available_balance, pocket status.
- Limits tab: editable per-pocket, per-limit-type table; inline edit; save produces audit record.
- Status History tab: immutable, paginated, chronological list.
- Audit Trail tab: filtered view of `platform.audit_events` for this wallet.

**Freeze/Unfreeze/Close Actions**:
- Confirmation modal with required reason text field.
- Current wallet status and pocket balances displayed before action.
- Success/error feedback includes `correlation_id`.
- Buttons not shown to users lacking the required permission.

**Accessibility**:
- All tables support keyboard navigation (Tab, Arrow keys for rows).
- Action buttons have ARIA labels.
- Confirmation modals trap focus; Escape dismisses.
- Error/success states announced via ARIA live regions.

---

## 18. Integration Boundaries

### 18.1 E06 Owns

- `wallet` PostgreSQL schema
- Tables: `wallets`, `currency_pockets`, `wallet_holds`, `wallet_limits`, `wallet_status_history`, `currencies`
- `WalletModuleContract` and `NullWalletModule`
- All balance computation logic (available balance formula)
- Wallet lifecycle state machine and enforcement
- Wallet domain events and outbox entries

### 18.2 E06 Consumes (does not own)

| Dependency | Interface | Provided By |
|---|---|---|
| User authentication | `identity.auth` middleware | E01 |
| Authorization | `identity.require-permission` middleware | E04 |
| KYC approval event | `kyc.case.approved` outbox event | E05 |
| Ledger balance | `LedgerServiceContract::getBalance()` | E07 |
| Idempotency service | `IdempotencyService` | Platform Foundation |
| Audit service | `AuditService::record()` | Platform Foundation |
| Outbox publisher | `OutboxEventPublisher::publish()` | Platform Foundation |
| Currency contract | `CurrencyContract` | Platform Foundation (existing) |
| Configuration service | `ConfigurationService` | Platform Foundation |

### 18.3 Explicitly Deferred to Future Epics

| Capability | Epic |
|---|---|
| Debit/credit ledger entry creation | E07 Wallet Ledger |
| Wallet funding (cash-in) | E08 Wallet Funding |
| Wallet-to-wallet transfer | E09 |
| Withdrawal / cash-out | E10 |
| Transaction management & history | E11 |
| FX conversion, exchange rates | E29 Multi-Currency Wallet |
| Fees and pricing | E21 |
| Fraud scoring / AML | E18 |
| Admin portal wallet UI screens | E33 Admin Application |
| Consumer mobile wallet UI screens | E31 Customer Application |
| Agent mobile wallet UI screens | E32 Agent Application |
| USDT blockchain custody or transfer | E29 / Future |
| CBDC integration | Future |
| Cross-currency aggregate limits | E29 |

### 18.4 Interface Contract: WalletModuleContract

The following interface MUST be fully implemented in E06 and remain stable for consuming epics:

```php
interface WalletModuleContract
{
    public function isActive(string $walletId): bool;
    public function ownerActorId(string $walletId): ?string;
    public function currency(string $walletId): CurrencyContract;
    public function pocketExists(string $walletId, string $currencyCode): bool;
    public function availableBalance(string $walletId, string $currencyCode): int;
}
```

This interface MUST be expanded (not replaced) when downstream epics require additional wallet data.

---

## 19. Success Criteria

**SC-E06-001** — A wallet is created successfully and appears with `status = pending` for a KYC-approved user after the `kyc.case.approved` event is processed; the creation SLA is measured but the target value is TBD-E06-006.

**SC-E06-002** — At no point in time does any currency pocket have a negative available balance; this is verified by a concurrency test exercising simultaneous debit operations against insufficient funds.

**SC-E06-003** — `available_balance = max(0, ledger_balance − held_amount)` holds true for all pockets at all tested points in time; any discrepancy is treated as a Critical defect.

**SC-E06-004** — All state-changing wallet operations with the same `Idempotency-Key` return identical responses; no duplicate wallet, pocket, history entry, or outbox event is created.

**SC-E06-005** — A frozen wallet rejects 100% of debit and credit transaction operations; balance read operations succeed for authorized actors on frozen wallets.

**SC-E06-006** — A transaction exceeding the configured limit is rejected before any ledger entry is created; this is confirmed by querying the E07 ledger account after the rejection. *(Full verification deferred to E07 integration testing; E06 verifies the rejection response and the absence of a hold placement.)*

**SC-E06-007** — Every wallet status transition is recorded in `wallet_status_history`; a wallet that has gone through all defined valid transitions has the expected number of immutable history entries.

**SC-E06-008** — All privileged operations (freeze, unfreeze, close, limit change, status change) produce an audit event in `platform.audit_events` with actor, wallet_id, operation, and timestamp.

**SC-E06-009** — Cross-user wallet access (IDOR) returns HTTP 404 for every E06 endpoint that accepts a wallet identifier; verified by security tests covering all 13 API endpoints.

**SC-E06-010** — Currency pockets for all 6 supported baseline currencies can be created independently on the same wallet; each has isolated balance and limit scope.

**SC-E06-011** — Domain events for all wallet mutations appear in `platform.outbox_events`; no wallet mutation produces a visible API response without a corresponding outbox entry in the same transaction.

**SC-E06-012** — `WalletModuleContract` is the only cross-module interface for wallet data; no other module accesses `wallet.*` database tables directly.

---

## 20. Edge Cases

**EC-E06-01**: `kyc.case.approved` delivered twice for the same user → second delivery is a no-op; idempotency detection logs a structured warning; no duplicate wallet.

**EC-E06-02**: Attempt to add a USDT pocket before TBD-E06-001 is resolved → pocket is created with `precision = null` sourced from the `currencies` table; downstream balance display uses whatever precision is configured.

**EC-E06-03**: Freeze requested for an already-frozen wallet (different `Idempotency-Key`) → HTTP 422 `INVALID_WALLET_TRANSITION` is returned (freeze → freeze is not a valid transition); with the same `Idempotency-Key`, HTTP 200 is returned (idempotent replay) (see US-WALLET-013 scenario 2 for the complementary same-key acceptance test).

**EC-E06-04**: Unfreeze requested for an `active` wallet → HTTP 422 `INVALID_WALLET_TRANSITION`.

**EC-E06-05**: Close requested for wallet with non-zero balance → HTTP 422 `WALLET_HAS_BALANCE`; no status transition; no pocket state change.

**EC-E06-06**: Two concurrent debit requests of 60 BWP against `available_balance = 100` → exactly one succeeds; the other returns `INSUFFICIENT_BALANCE`; final `available_balance = 40`, never negative.

**EC-E06-07**: Transaction amount exactly equals available balance → permitted; debit of 100 against available 100 succeeds.

**EC-E06-08**: `wallet_reference` generation produces a collision (extremely rare) → the generation algorithm retries with a new reference; wallet creation does not fail.

**EC-E06-09**: Debit requested while `LedgerServiceContract::getBalance()` is temporarily unavailable → operation is rejected with a service-unavailable error; no inconsistent state is created; `Idempotency-Key` allows safe retry.

**EC-E06-10**: Hold on a pocket where `expires_at` has passed but the `wallet:expire-holds` job has not yet run → the hold is still counted in `held_amount`; available balance is conservative until the job runs.

**EC-E06-11**: Admin attempts to close a wallet with no pockets (pathological case) → closure succeeds; no pocket state changes are needed.

**EC-E06-12**: A `pending` wallet receives an event from another module checking `WalletModuleContract::isActive()` → returns `false`; the calling module handles the not-active case without error.

---

## 21. TBDs

| ID | Description | Impact if Unresolved |
|---|---|---|
| TBD-E06-001 | USDT decimal precision (2 or 6 places) | USDT pocket `precision` stored as null; USDT balances cannot be accurately displayed |
| TBD-E06-002 | Step-up / MFA requirement for freeze, unfreeze, close operations | These operations proceed without step-up until resolved |
| TBD-E06-003 | Admin override for closing a wallet with non-zero balance | Closure strictly requires zero balance until resolved |
| TBD-E06-004 | Seed values for wallet limits (daily, monthly, per-transaction, max balance) | All limits seeded as `is_tbd = true`; no limit enforcement until resolved |
| TBD-E06-005 | Rate limits for wallet creation and balance inquiry endpoints | No rate limiting applied until resolved |
| TBD-E06-006 | Wallet creation SLA after `kyc.case.approved` event | No SLA target; latency measured but no alerting threshold set |
| TBD-E06-007 | Wallet reference number format and generation algorithm | Temporary format (e.g., UUID-prefix) used until resolved |
| TBD-E06-008 | Pocket creation strategy: eager (all 6 at creation) vs lazy (on demand) | Default: lazy (BWP only at creation); others created explicitly |
| TBD-E06-009 | Schedule cadence for `wallet:expire-holds` Artisan command | Command registered but run frequency undefined; no holds expire automatically until resolved |

All TBD values MUST be represented in `platform.configuration_values` with `is_tbd = true`. When resolved, a migration adds the resolved value and sets `is_tbd = false`. No TBD value may be invented or hard-coded in domain logic.

---

## 22. Epic-Level Definition of Done

- All 17 stories in this epic meet their story-specific Definition of Done.
- Cross-story dependencies are tested (balance enforcement covers limit checks; concurrency tests exercise simultaneous debits; IDOR tests cover all endpoints).
- API contracts and migrations are reviewed and consistent with this spec.
- Security and authorization checks pass: authentication, ownership validation, IDOR prevention, audit, privileged-operation controls.
- UI states and accessibility requirements pass for Consumer, Agent, and Admin surfaces.
- `WalletModuleContract` is fully implemented and verified; `NullWalletModule` throws `ModuleNotImplementedException` for unimplemented methods.
- All domain events appear in `platform.outbox_events` for every corresponding mutation.
- All TBDs are documented with `is_tbd = true` in configuration; no TBD value is hard-coded.
- Concurrency tests confirm no negative balance under simultaneous debit pressure.
- No unresolved Critical/High defect blocks completion.
