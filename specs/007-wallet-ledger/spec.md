# E07 — Wallet Ledger

**Feature**: `007-wallet-ledger` | **Epic**: E07 | **Status**: Draft | **Created**: 2026-09-16

---

## 1. Problem Statement

E06 (Wallet Management) establishes the wallet and currency-pocket structure and defines a balance formula: `available_balance = max(0, ledger_balance − held_amount)`. However, the authoritative source of `ledger_balance` — the double-entry ledger itself — does not yet exist. E06's `LedgerModuleContract::getBalance()` currently throws `ModuleNotImplementedException` via the `NullLedgerModule` stub, and the `wallet.stub_ledger_balance` configuration flag must be set `true` in test environments to prevent hard failures.

Without E07, no transaction can be posted, no settled balance can be computed, and the entire Financial Core dependency chain (E08 Funding → E09 Transfers → E10 Withdrawals → E11 Transactions → E21 Fees) is blocked. Every downstream financial epic posts entries to the ledger and queries balances from it.

E07 establishes the **authoritative, immutable, double-entry ledger** for the OleKard Ecosystem. It owns ledger accounts, journal entries, journal lines, balance computation, reversal mechanics, and the `LedgerModuleContract` concrete implementation that replaces the null stub in E06 and all future epics.

---

## 2. Scope

E07 covers:

- **LedgerAccount lifecycle**: registration on first use via the pre-assigned `ledger_account_reference` from E06; account status; account types (asset, liability, income, expense, equity)
- **Journal posting**: atomic, balanced double-entry journals (`Journal` + `JournalLine` entities); entry types: debit, credit, fee, revenue, commission, reversal
- **Immutability enforcement**: no UPDATE or DELETE on posted journal lines; reversals use compensating entries
- **Balance computation**: `LedgerModuleContract::getBalance(ledger_account_reference)` — authoritative settled balance derived from all posted lines
- **Idempotent posting**: duplicate posts with the same `Idempotency-Key` return the original journal response without creating a second entry
- **Reversal**: privileged operation that posts an equal and opposite compensating journal, linked to the original
- **Query**: paginated entry history per account; entry history per transaction reference
- **Imbalance detection**: `ledger:check-balance` Artisan command verifying, per `currency_code`, that SUM(debits) == SUM(credits) within each currency
- **Domain events**: `Ledger.Entry.Posted`, `Ledger.Entry.Reversed`, `Ledger.Account.Registered`, `Ledger.Imbalance.Detected`
- **Audit trail**: all postings and reversals recorded in `platform.audit_events`
- **`LedgerModuleContract` implementation**: replaces `NullLedgerModule`; existing three methods implemented; contract expanded with two new methods
- **Permissions**: `ledger.*` permissions seeded for Admin and System actors
- **Architecture integrity test**: asserts no module outside `Ledger` accesses `Journal`, `JournalLine`, or `LedgerAccount` Eloquent models directly

---

## 3. Goals

1. Provide an immutable, auditable double-entry ledger that is the sole source of financial truth for the ecosystem.
2. Implement `LedgerModuleContract` so that E06's `wallet.stub_ledger_balance` flag can be removed from production and test configurations.
3. Enforce balanced-journal invariant at every posting — partial postings are prohibited at the database and application layers.
4. Prevent any code path from updating or deleting a posted journal line.
5. Enable upstream epics (E08, E09, E10, E11) to post entries and query balances through `LedgerModuleContract` without directly accessing ledger domain models.
6. Emit domain events from every mutation so reconciliation, compliance, and audit consumers can react.
7. Support all six baseline currencies (BWP, USD, EUR, ZAR, CNY, USDT) without currency-specific code paths.

---

## 4. Non-Goals

E07 does NOT:

- Implement wallet funding / cash-in (E08).
- Implement wallet-to-wallet transfers (E09).
- Implement withdrawals (E10).
- Implement transaction management or transaction orchestration (E11).
- Implement fees, commissions, or pricing rules (E21).
- Implement FX conversion or cross-currency exchange rates (E29).
- Implement reconciliation or settlement (E20).
- Implement fraud or AML controls (E18).
- Define or provision specific system account names, types, or float accounts — that is TBD-E07-001 (resolved as a configuration/migration decision before E08).
- Implement UI screens for ledger operations — the ledger is a service-to-service API; Admin ledger read views belong to E19/E23.
- Process outbound provider payments or manage external references (future epics).
- Define fee rates or commission schedules (E21).

---

## 5. Actors

| Actor | Description | Ledger Permissions |
|---|---|---|
| **System / Wallet Service** | Internal service actor (E06, E08–E11) posting journal entries via `LedgerModuleContract` | `ledger.post`, `ledger.account.register`, `ledger.read` |
| **Admin** | Admin user querying ledger balances, history, and audit trails; approving reversals | All ledger permissions |
| **Compliance Officer** | Admin actor requesting privileged reversal | `ledger.entry.reverse`, `ledger.read.any` |
| **System / Audit Runner** | Internal process running `ledger:check-balance` | Read-only; no posting permissions |

Direct Customer or Agent access to ledger API endpoints is **not** permitted. Customers view their balance and history through E06 Wallet API, which calls `LedgerModuleContract` internally.

---

## 6. User Stories

### US-LEDGER-001 — Post Debit Journal Entry (P1)

**Actor**: System (Wallet / Transaction service)

**Story**: As a transaction service, I want to post a debit journal entry against a wallet pocket account, so that when a customer initiates a spend or withdrawal, the ledger balance decreases atomically with the corresponding credit to a counterpart account, preserving double-entry integrity.

**Why this priority**: Debit posting is the most fundamental financial operation. Every outbound money movement (spend, transfer, fee, withdrawal) begins here.

**Independent Test**: POST a balanced debit journal to a known account reference → journal record created with `status = posted`; `getBalance()` for the debited account decreases by the correct amount; `Ledger.Entry.Posted` event appears in outbox.

**Acceptance Scenarios**:

1. **Given** a ledger account with reference `R` has a balance of 10,000 (BWP thebe), **When** a balanced debit journal is posted (debit R for 2,000; credit counterpart for 2,000), **Then** a `Journal` record is created with `status = posted`; a `JournalLine` with `direction = debit, amount = 2000` exists for account `R`; `getBalance(R)` returns 8,000; a `Ledger.Entry.Posted` event appears in the outbox.
2. **Given** a posting request where `Idempotency-Key: K1` has been used before, **When** the same key is submitted again, **Then** the original journal ID is returned; no second `Journal` record is created; the balance is unchanged.
3. **Given** a balanced debit journal request with a valid `Idempotency-Key`, **When** the DB transaction commits, **Then** all journal lines are persisted atomically; no partial commit is possible.
4. **Given** a debit journal where `SUM(debit amounts) ≠ SUM(credit amounts)`, **When** the journal is posted, **Then** HTTP 422 `UNBALANCED_JOURNAL` is returned; no journal record is created; no balance change occurs.
5. **Given** an unauthorized actor (missing `ledger.post` permission), **When** posting a debit entry, **Then** HTTP 403 is returned; no entry is created.

---

### US-LEDGER-002 — Post Credit Journal Entry (P1)

**Actor**: System (Wallet / Transaction service)

**Story**: As a transaction service, I want to post a credit journal entry to a wallet pocket account, so that when a customer receives funds (deposit, inbound transfer, reversal), the ledger balance increases atomically with a corresponding debit to the counterpart account.

**Independent Test**: POST a balanced credit journal to a known account reference → `getBalance()` increases; `Ledger.Entry.Posted` in outbox.

**Acceptance Scenarios**:

1. **Given** an account `R` with balance 5,000, **When** a credit journal of 3,000 is posted (credit R; debit counterpart), **Then** `getBalance(R)` returns 8,000; `Journal.status = posted`.
2. **Given** a zero-balance account, **When** a credit of 1,000 is posted, **Then** `getBalance()` returns 1,000; the account is auto-registered if not previously registered.
3. **Given** a credit journal with zero amount on any line, **When** posted, **Then** HTTP 422 `INVALID_AMOUNT` is returned; no entry created.
4. **Given** a credit journal with `currency_code = XYZ` not in the currencies table, **When** posted, **Then** HTTP 422 `CURRENCY_MISMATCH` is returned.
5. **Given** the same credit journal is submitted twice with different `Idempotency-Key` values but identical payload, **When** both are processed, **Then** two separate journal entries are created; balance reflects both credits.

---

### US-LEDGER-003 — Post Fee Journal Entry (P1)

**Actor**: System (Wallet / Transaction / Fee service)

**Story**: As a fee processing service, I want to post a fee journal entry that debits a customer wallet account and credits the platform's fee income account, so that fee collection is recorded in the ledger with the same double-entry integrity as any other financial posting.

**Independent Test**: POST a fee entry → customer pocket account debited; platform fee account credited; net system balance unchanged.

**Acceptance Scenarios**:

1. **Given** a customer pocket with balance 10,000 and a configured platform fee account, **When** a fee entry of 200 is posted (debit customer pocket; credit fee income account), **Then** customer pocket balance decreases to 9,800; fee income account balance increases by 200; total system SUM(credits) == SUM(debits).
2. **Given** the fee entry posting fails (DB rollback), **When** the failure occurs, **Then** neither the customer pocket debit nor the fee income credit is persisted; the outbox event is not emitted.
3. **Given** a fee entry references a platform fee account reference that has no prior history, **When** posted, **Then** the account is auto-registered; the journal posts normally.
4. **Given** an actor without `ledger.post` permission attempts to post a fee entry, **When** the request arrives, **Then** HTTP 403 is returned.
5. **Given** a fee entry with `amount = 0`, **When** posted, **Then** HTTP 422 `INVALID_AMOUNT` is returned.

---

### US-LEDGER-004 — Post Revenue Journal Entry (P1)

**Actor**: System (Revenue / Settlement service)

**Story**: As a revenue service, I want to post a revenue journal entry to a platform revenue account, so that income recognition events are recorded immutably in the ledger and queryable for reconciliation and audit.

**Independent Test**: POST a balanced revenue journal → platform revenue account balance increases; corresponding asset/liability account adjusted; balanced.

**Acceptance Scenarios**:

1. **Given** a platform revenue account `REV-001` with balance 0, **When** a revenue entry of 5,000 is posted, **Then** `getBalance(REV-001)` returns 5,000; the counterpart account is adjusted accordingly.
2. **Given** a revenue entry is posted successfully, **When** `GET /v1/ledger/accounts/{ref}/entries` is called, **Then** the revenue line appears in the paginated history.
3. **Given** an unbalanced revenue entry (credit sum ≠ debit sum), **When** posted, **Then** HTTP 422 `UNBALANCED_JOURNAL` is returned.
4. **Given** the same `Idempotency-Key` is used for a revenue entry a second time, **When** the duplicate arrives, **Then** the original journal ID is returned; no new entry is created.
5. **Given** a revenue entry with a `transaction_reference` linking it to a prior transaction, **When** retrieved via `GET /v1/ledger/journals/{id}`, **Then** the `transaction_reference` is present in the response.

---

### US-LEDGER-005 — Post Commission Journal Entry (P1)

**Actor**: System (Commission / Agent service)

**Story**: As a commission service, I want to post a commission journal entry that credits an agent's commission account and debits the platform source account, so that agent earnings are tracked in the authoritative ledger from the moment they are accrued.

**Independent Test**: POST a commission entry → agent commission account credited; platform source debited; both sides persisted atomically.

**Acceptance Scenarios**:

1. **Given** an agent commission account and a platform source account, **When** a commission of 1,500 is posted, **Then** the agent account is credited 1,500; the source account is debited 1,500; net balance is zero.
2. **Given** an agent commission account that has never been posted to, **When** the first commission entry arrives, **Then** the account is auto-registered and the posting succeeds.
3. **Given** a commission entry is posted, **When** an audit query is run, **Then** `platform.audit_events` contains an entry with `action = ledger.entry.posted`, `resource_type = journal`, and the journal ID.
4. **Given** an unbalanced commission entry, **When** posted, **Then** HTTP 422 `UNBALANCED_JOURNAL` is returned.
5. **Given** a commission entry for a currency not matching the agent account's denomination, **When** posted, **Then** HTTP 422 `CURRENCY_MISMATCH` is returned.

---

### US-LEDGER-006 — Maintain Balanced Journal (P1)

**Actor**: System (Ledger service / any posting consumer)

**Story**: As the ledger, I want to guarantee that every committed journal has exactly equal total debits and credits, so that the accounting equation is always preserved and no funds appear or disappear due to software errors.

**Independent Test**: Attempt to commit both a balanced and an unbalanced journal — balanced commits; unbalanced is rejected before any line is persisted.

**Acceptance Scenarios**:

1. **Given** a journal with three lines (debit A 1,000; credit B 600; credit C 400), **When** posted, **Then** the journal commits successfully (1,000 == 600 + 400).
2. **Given** a journal with SUM(debits) = 1,000 and SUM(credits) = 999, **When** posted, **Then** HTTP 422 `UNBALANCED_JOURNAL` is returned; zero lines are inserted.
3. **Given** a journal with a single debit line and no credit lines, **When** posted, **Then** HTTP 422 `UNBALANCED_JOURNAL` is returned.
4. **Given** a journal with all lines having `amount = 0`, **When** posted, **Then** HTTP 422 `INVALID_AMOUNT` is returned.
5. **Given** ten concurrent balanced journal postings to the same pair of accounts, **When** all commit, **Then** `ledger:check-balance` shows SUM(all debits) == SUM(all credits); no imbalance.

---

### US-LEDGER-007 — Prevent Unbalanced Transaction (P1)

**Actor**: System (Ledger domain integrity)

**Story**: As the ledger integrity layer, I want to reject any posting attempt where debits and credits do not balance, so that malformed requests from upstream services never corrupt the ledger.

**Independent Test**: POST journals with various imbalance conditions → all rejected with `UNBALANCED_JOURNAL`; DB contains no orphan lines.

**Acceptance Scenarios**:

1. **Given** a journal request with `debit_total = 500, credit_total = 600`, **When** posted, **Then** HTTP 422 `UNBALANCED_JOURNAL` is returned; no `Journal` record exists; no `JournalLine` records exist.
2. **Given** a journal request with no lines, **When** posted, **Then** HTTP 422 `UNBALANCED_JOURNAL` is returned.
3. **Given** a journal with lines of mixed currencies (BWP and USD), **When** posted, **Then** HTTP 422 `CURRENCY_MISMATCH` is returned.
4. **Given** a journal request with a negative amount on a line, **When** posted, **Then** HTTP 422 `INVALID_AMOUNT` is returned.
5. **Given** the posting service crashes mid-transaction after inserting the journal header but before inserting lines, **When** the transaction rolls back, **Then** the journal header is also rolled back; no orphan record exists.

---

### US-LEDGER-008 — Ledger Entries Immutable (P1)

**Actor**: Any consumer / compliance

**Story**: As a compliance and audit stakeholder, I want ledger entries to be immutable after posting, so that the financial record cannot be altered and any modification attempt is rejected at the repository layer.

**Independent Test**: Post a journal → attempt to update or delete a journal line → both fail; the original line is unchanged.

**Acceptance Scenarios**:

1. **Given** a posted journal line exists, **When** code attempts to call `save()` on the model if it exists (update path), **Then** a `LogicException` is thrown; the line is unchanged in the database.
2. **Given** a posted journal line exists, **When** code attempts to call `delete()` on the model, **Then** a `LogicException` is thrown; the line remains in the database.
3. **Given** a posted `Journal` header record, **When** code attempts to change its `status` field directly, **Then** a `LogicException` is thrown; status remains `posted`.
4. **Given** a DB migration that accidentally contains `UPDATE journal_lines`, **When** the architecture integrity test runs, **Then** the test detects the violation and fails.
5. **Given** an Admin attempts to update a journal entry via the Admin API, **Then** no such endpoint exists; HTTP 405 is returned; no modification path is exposed.

---

### US-LEDGER-009 — Reverse Entry via Compensating Journal (P1)

**Actor**: Compliance Officer / Admin (privileged)

**Story**: As a compliance officer, I want to reverse a posted journal entry by posting a compensating journal with equal and opposite lines, so that financial corrections are auditable and the original immutable record is preserved.

**Independent Test**: POST a reversal for a known journal → a new journal is created with reversed lines; original journal unchanged; `getBalance()` returns net of both journals; `Ledger.Entry.Reversed` event emitted.

**Acceptance Scenarios**:

1. **Given** journal `J1` with lines (debit A 1,000; credit B 1,000), **When** a reversal is posted for `J1`, **Then** a new journal `J2` is created with lines (credit A 1,000; debit B 1,000); `J1` is unchanged; `J2.reversed_journal_id = J1.id`; both `getBalance(A)` and `getBalance(B)` return their pre-`J1` values.
2. **Given** journal `J1` has already been reversed (a reversal `J2` exists), **When** another reversal is requested for `J1`, **Then** HTTP 422 `JOURNAL_ALREADY_REVERSED` is returned.
3. **Given** a reversal request without `ledger.entry.reverse` permission, **When** submitted, **Then** HTTP 403 is returned; no reversal is created.
4. **Given** a reversal request with a valid `Idempotency-Key`, **When** the same key is replayed, **Then** the original reversal journal ID is returned; no second reversal is created.
5. **Given** a reversal is posted, **When** audit trail is queried, **Then** `platform.audit_events` contains an entry with `action = ledger.entry.reversed`, the original journal ID, the reversal journal ID, and the actor ID.

---

### US-LEDGER-010 — Retrieve Wallet Ledger (P1)

**Actor**: Admin / Wallet service (via `LedgerModuleContract::getAccountHistory()`)

**Story**: As an Admin or internal service, I want to retrieve the paginated list of journal lines for a wallet pocket account, so that account history can be displayed, reconciled, or exported.

**Independent Test**: POST three journals against account `R` → `GET /v1/ledger/accounts/{R}/entries` returns all three lines in descending `posted_at` order with pagination metadata.

**Acceptance Scenarios**:

1. **Given** account `R` has 20 posted journal lines, **When** `GET /v1/ledger/accounts/{R}/entries?page=1&per_page=10` is called, **Then** 10 lines are returned; `meta.total = 20`; `meta.pages = 2`.
2. **Given** account `R` has zero posted lines, **When** entries are retrieved, **Then** an empty array is returned; `meta.total = 0`; HTTP 200.
3. **Given** an actor without `ledger.read` permission requests entries for account `R`, **When** the request arrives, **Then** HTTP 403 is returned.
4. **Given** an actor without `ledger.read.any` permission requests entries for an account they do not own, **When** the request arrives, **Then** HTTP 404 is returned (IDOR prevention).
5. **Given** account `R` has lines from multiple journal entries (debit and credit), **When** entries are retrieved, **Then** each line includes: `journal_id`, `direction`, `amount`, `currency_code`, `posted_at`, `entry_type`, `transaction_reference`.

---

### US-LEDGER-011 — Retrieve Transaction Ledger (P1)

**Actor**: Admin / Transaction service (E11)

**Story**: As a transaction service, I want to retrieve all journal lines associated with a given `transaction_reference`, so that the full double-entry impact of a transaction can be traced, audited, or confirmed as complete.

**Independent Test**: POST a journal with `transaction_reference = TXN-001` → `GET /v1/ledger/journals?transaction_reference=TXN-001` returns all lines for that reference.

**Acceptance Scenarios**:

1. **Given** two journals were posted with `transaction_reference = TXN-001`, **When** queried by reference, **Then** both journals and all their lines are returned.
2. **Given** `transaction_reference = TXN-UNKNOWN` does not match any journal, **When** queried, **Then** an empty array is returned; HTTP 200.
3. **Given** an actor without `ledger.read` permission queries by transaction reference, **When** the request arrives, **Then** HTTP 403 is returned.
4. **Given** a transaction reference spans a debit entry and its subsequent reversal, **When** queried, **Then** both the original journal and the reversal journal are returned.
5. **Given** the transaction ledger endpoint is called with pagination parameters, **When** processing the request, **Then** paginated results are returned in the standard platform envelope.

---

### US-LEDGER-012 — Calculate Balance from Ledger (P1)

**Actor**: E06 Wallet service (via `LedgerModuleContract::getBalance()`) / Admin

**Story**: As the wallet service, I want to compute the settled balance for a ledger account at any point in time by summing all credit entries and subtracting all debit entries, so that E06 can compute `available_balance` accurately.

**Independent Test**: Post N journals against account `R` → `getBalance(R)` returns exactly SUM(credits) − SUM(debits) for all posted lines on `R`.

**Acceptance Scenarios**:

1. **Given** account `R` has lines: credit 10,000; debit 3,000; credit 5,000; debit 1,000, **When** `getBalance(R)` is called, **Then** it returns 11,000 (10,000 + 5,000 − 3,000 − 1,000).
2. **Given** account `R` has no posted lines, **When** `getBalance(R)` is called, **Then** 0 is returned.
3. **Given** `ledger_account_reference` does not exist, **When** `getBalance()` is called, **Then** 0 is returned (account has no entries; not found is treated as zero balance).
4. **Given** a reversal journal was posted against account `R`, **When** `getBalance(R)` is called, **Then** the reversal's lines are included in the calculation; the net balance reflects both original and reversal.
5. **Given** `getBalance()` is called concurrently with an in-flight posting, **When** the read is served, **Then** the result is either pre-posting or post-posting state; no dirty read is returned.

---

### US-LEDGER-013 — Detect Ledger Imbalance (P2)

**Actor**: System (Audit / Compliance runner)

**Story**: As a compliance system, I want to run a periodic check that verifies the entire ledger is balanced (SUM of all debit lines == SUM of all credit lines across all journals and currencies), so that any corruption is immediately detected and escalated.

**Independent Test**: Run `ledger:check-balance` on a clean ledger → passes; manually inject an orphan debit line → command detects imbalance; `Ledger.Imbalance.Detected` event emitted.

**Acceptance Scenarios**:

1. **Given** the ledger is clean (all journals balanced), **When** `ledger:check-balance` runs, **Then** the command exits 0; no `Ledger.Imbalance.Detected` event is emitted; a structured log entry reports "balance check passed".
2. **Given** a test environment where an orphan debit line has been manually inserted, **When** the command runs, **Then** it detects the imbalance; emits a `Ledger.Imbalance.Detected` event to the outbox; exits non-zero; logs a CRITICAL-severity structured entry.
3. **Given** the command runs concurrently with active postings, **When** it completes, **Then** it produces no deadlocks and does not block postings; the command is read-only.
4. **Given** the command is run twice in quick succession, **When** both runs complete, **Then** the second run produces the same result as the first; the command is idempotent.
5. **Given** an imbalance is detected, **Then** no automatic correction is applied; the ledger remains unchanged; human intervention is required.

---

### US-LEDGER-014 — Generate Ledger Audit Trail (P2)

**Actor**: Admin / Compliance Officer

**Story**: As a compliance officer, I want to retrieve a complete, tamper-evident audit trail for all ledger operations (postings, reversals, account registrations, imbalance detections), so that every financial action can be attributed to an actor, correlated to a request, and presented to auditors.

**Independent Test**: POST a journal, reverse it, and query audit events → two events found: `ledger.entry.posted` and `ledger.entry.reversed`; each includes actor_id, journal_id, occurred_at, correlation_id.

**Acceptance Scenarios**:

1. **Given** a journal is posted by actor `A` with correlation ID `C`, **When** `platform.audit_events` is queried, **Then** an event with `action = ledger.entry.posted`, `actor_id = A`, `resource_id = journal_id`, `correlation_id = C`, `occurred_at` is found.
2. **Given** a reversal is posted by actor `A2`, **When** audit events are queried, **Then** an event with `action = ledger.entry.reversed` references both the original and reversal journal IDs.
3. **Given** a new ledger account is registered, **When** audit events are queried, **Then** an event with `action = ledger.account.registered` and the account reference is found.
4. **Given** a ledger imbalance is detected by the scheduled command, **When** audit events are queried, **Then** an event with `action = ledger.imbalance.detected` and `severity = CRITICAL` is found.
5. **Given** audit events are queried across a large date range, **When** the response is returned, **Then** events are returned in `occurred_at` ascending order with pagination metadata.

---

## 7. Functional Requirements

### LedgerAccount Entity

**FR-E07-001** — A `LedgerAccount` SHALL have: `id` (UUID v7, system identifier), `ledger_account_reference` (UUID v7, unique opaque reference pre-assigned by the consuming module — e.g., E06 pocket creation), `account_type` (ENUM: `asset`, `liability`, `income`, `expense`, `equity`), `normal_balance` (ENUM: `debit`, `credit` — derived from account_type at registration), `currency_code` (varchar(10)), `owner_reference` (UUID, opaque reference to owning entity), `owner_type` (varchar, e.g., `customer_pocket`, `system_float`, `system_revenue`, `agent_commission`), `status` (ENUM: `active`, `closed`), `created_at`, `updated_at`, `created_by`.

**FR-E07-002** — `ledger_account_reference` is globally unique across all accounts; a unique constraint is enforced at the database level.

**FR-E07-003** — LedgerAccounts are registered either explicitly via `POST /v1/ledger/accounts` or automatically on first `postEntry()` call for a previously unknown `ledger_account_reference`. Auto-registration uses `account_type = liability, normal_balance = credit` as the **provisional default**, consistent with the E06 balance formula (`SUM(credits) − SUM(debits)`). This default is contingent on TBD-E07-002 resolution; if TBD-E07-002 resolves to `asset`, consuming modules that relied on auto-registration would silently carry the wrong account type. Consuming modules (E08+) SHOULD therefore explicitly register accounts with the correct `account_type` via `registerAccount()` rather than relying on auto-registration once TBD-E07-002 is resolved. Because the `postEntry()` request array (FR-E07-044) does not carry `owner_reference` or `owner_type` per line, auto-registration sets both fields to `NULL`; explicit `registerAccount()` callers MUST supply non-null values for both. A `NULL owner_reference` on a `ledger_accounts` row is the sentinel indicating the account was auto-registered without explicit ownership context.

**FR-E07-004** — LedgerAccounts are never deleted. A `closed` account accepts no further postings; it returns HTTP 422 `ACCOUNT_CLOSED` if a posting is attempted.

**FR-E07-005** — System accounts (float, revenue, fee, commission, suspense) are provisioned by a seeder migration at deployment time; their `ledger_account_reference` values are stored in `platform.configuration_values` and are TBD-E07-001 pending business decision. No system account reference may be hard-coded in domain logic. Configuration keys for system account references SHALL follow the convention `ledger.system.{account_type}_account_ref` (e.g., `ledger.system.float_account_ref`, `ledger.system.fee_account_ref`, `ledger.system.revenue_account_ref`); the full set of key names is within TBD-E07-001 scope and MUST be documented in the seeder migration comment or `config/ledger.php` stub before E08.

### Journal and JournalLine Entities

**FR-E07-006** — A `Journal` SHALL have: `id` (UUID v7), `idempotency_key` (varchar(255), unique), `entry_type` (ENUM: `debit`, `credit`, `fee`, `revenue`, `commission`, `reversal`), `currency_code` (varchar(10)), `status` (ENUM: `posted` — append-only; `void` is achieved only via reversal), `transaction_reference` (varchar(255), nullable, cross-module reference), `reversed_journal_id` (UUID, nullable, FK to `journals.id`), `correlation_id` (varchar(255)), `description` (text, nullable), `posted_at` (timestamptz UTC), `created_by` (UUID actor).

**FR-E07-007** — A `JournalLine` SHALL have: `id` (UUID v7), `journal_id` (UUID FK → `journals.id`), `ledger_account_id` (UUID FK → `ledger_accounts.id`), `direction` (ENUM: `debit`, `credit`), `amount` (bigint, positive, minor currency units), `currency_code` (varchar(10)), `created_at` (timestamptz UTC).

**FR-E07-008** — Every `Journal` MUST contain at least one `debit` line and at least one `credit` line; a journal with only debit lines or only credit lines SHALL be rejected with HTTP 422 `UNBALANCED_JOURNAL`.

**FR-E07-009** — `SUM(amount WHERE direction = 'debit')` MUST equal `SUM(amount WHERE direction = 'credit')` within a single journal. Any deviation rejects the journal before any persistence occurs.

**FR-E07-010** — All `JournalLine` records within one `Journal` MUST share the same `currency_code` as the parent `Journal`. Mixed-currency journals are rejected with HTTP 422 `CURRENCY_MISMATCH`.

**FR-E07-011** — A journal posts atomically: all lines are committed or none are. Partial commitment is prohibited. The journal header and all lines are inserted within a single database transaction along with the outbox event.

**FR-E07-012** — `JournalLine` records are append-only. The `JournalLine` model SHALL throw `LogicException` if `save()` is called on an existing record (`$this->exists === true`). The `delete()` method SHALL always throw `LogicException`. This constraint is enforced at the application layer and MAY additionally be enforced via database-level triggers or row-level security.

**FR-E07-013** — The `Journal` `status` field is write-once. No application code path may directly change `status` from `posted` to any other value; reversals produce new journal records.

### Entry Types and Double-Entry Semantics

**FR-E07-014** — **Debit entry** (`entry_type = debit`): the primary debit line(s) SHALL specify a customer wallet pocket account or any normal-credit liability account being reduced; the credit line(s) SHALL specify a system counterpart account or the opposing leg. Consumers supply both sides.

**FR-E07-015** — **Credit entry** (`entry_type = credit`): the primary credit line(s) SHALL specify a customer wallet pocket or any normal-credit liability being increased; the debit line(s) SHALL specify a system counterpart.

**FR-E07-016** — **Fee entry** (`entry_type = fee`): a classification label indicating that the journal records a fee charge. The ledger does NOT validate which accounts appear on which side; account role correctness is the responsibility of the posting caller. The fee account references used by the caller are resolved via `platform.configuration_values` (TBD-E07-001); no fee account reference may be hard-coded in domain logic.

**FR-E07-017** — **Revenue entry** (`entry_type = revenue`): a classification label indicating that the journal records a revenue recognition event. The ledger does NOT validate which accounts appear on which side; the posting service is responsible for correct account selection.

**FR-E07-018** — **Commission entry** (`entry_type = commission`): a classification label indicating that the journal records a commission accrual. The ledger does NOT validate which accounts appear on which side; the posting service is responsible for correct account selection.

**FR-E07-019** — **Reversal entry** (`entry_type = reversal`): produced only by the reversal endpoint; lines are an exact mirror of the original journal's lines with directions swapped; `reversed_journal_id` is set; original journal is not modified.

### Balance Computation

**FR-E07-020** — For accounts with `normal_balance = credit` (default for customer pockets, income, equity, liability accounts): `balance = SUM(amount WHERE direction = 'credit') − SUM(amount WHERE direction = 'debit')` across all posted `JournalLine` records for that account.

**FR-E07-021** — For accounts with `normal_balance = debit` (asset, expense accounts): `balance = SUM(amount WHERE direction = 'debit') − SUM(amount WHERE direction = 'credit')` across all posted `JournalLine` records.

**FR-E07-022** — `LedgerModuleContract::getBalance(string $ledgerAccountReference): int` SHALL return the balance using the formula appropriate to the account's `normal_balance`. An unknown reference (never registered, never posted) returns `0`.

**FR-E07-023** — Balance queries MUST be performed at `READ COMMITTED` isolation or higher to prevent dirty reads. Balance queries within an active transaction reflect the committed state visible to that transaction. When called from within an existing transaction scope (e.g., E06's `SELECT FOR UPDATE` debit enforcement), `getBalance()` MUST use the same DB connection and transaction to return a consistent view; the implementation MUST NOT open a separate read-only connection inside a caller-supplied transaction scope, as doing so would produce an inconsistent snapshot under concurrent writes.

**FR-E07-024** — A running balance column is NOT maintained on `ledger_accounts` as a mutable field (constitution Principle 4). Balance is always computed from journal lines. Balance snapshots or materialized views for performance optimization are TBD-E07-003.

### Idempotency

**FR-E07-025** — All mutation endpoints (`POST /v1/ledger/accounts`, `POST /v1/ledger/entries/*`, `POST /v1/ledger/entries/{id}/reverse`) MUST require an `Idempotency-Key` header. Requests without this header return HTTP 422. (SR-E07-004 restates this requirement in the security context; the canonical source is this FR.)

**FR-E07-026** — Duplicate posts with the same `Idempotency-Key` SHALL return the original journal response (HTTP 200 with the original `journal_id`) without creating a new journal entry. Detection uses the Platform `IdempotencyService` (PostgreSQL-authoritative, Redis read-through per OKI-ARCH-2026-003).

**FR-E07-027** — `LedgerModuleContract::entryExists(string $transactionReference): bool` returns `true` if any journal with that `transaction_reference` exists in `posted` status. This is a fast path for upstream services to check before posting.

### Reversal

**FR-E07-028** — Reversal is triggered by `POST /v1/ledger/entries/{id}/reverse` with a required `Idempotency-Key`. It creates a new `Journal` with `entry_type = reversal`, lines that are exact mirrors of the original journal's lines (debit↔credit swapped), and `reversed_journal_id` pointing to the original.

**FR-E07-029** — A journal that has already been reversed (`reversed_journal_id` is set on an existing reversal journal referencing it) SHALL return HTTP 422 `JOURNAL_ALREADY_REVERSED` on any further reversal attempt.

**FR-E07-030** — Reversal requires `ledger.entry.reverse` permission. Step-up / maker-checker workflow requirements are TBD-E07-005 and MUST NOT be hard-coded as always-required.

**FR-E07-031** — A reversal journal is itself immutable; it may not be updated, deleted, or re-reversed.

### Query

**FR-E07-032** — `GET /v1/ledger/accounts/{reference}/entries` returns a paginated list of `JournalLine` records for the given account reference, ordered by `posted_at` descending. Each line includes: `journal_id`, `entry_type`, `direction`, `amount`, `currency_code`, `transaction_reference`, `posted_at`.

**FR-E07-033** — `GET /v1/ledger/journals?transaction_reference={ref}` returns all journals and their lines matching the given `transaction_reference`. This endpoint enables E11 Transaction Management to verify whether a transaction has been fully posted.

**FR-E07-034** — `GET /v1/ledger/journals/{id}` returns the full journal record including all lines.

**FR-E07-035** — All list endpoints follow platform pagination conventions: `page`, `per_page` query params; response envelope includes `meta.total`, `meta.page`, `meta.per_page`, `meta.pages`.

### Imbalance Detection

**FR-E07-036** — An Artisan command `ledger:check-balance` SHALL verify, **per `currency_code`**, that `SUM(amount WHERE direction = 'debit') == SUM(amount WHERE direction = 'credit')` within each distinct currency across all `journal_lines`. Summing amounts across currencies is financially meaningless and MUST NOT be performed. For each currency where the sums diverge, a `Ledger.Imbalance.Detected` event is emitted to the outbox and a CRITICAL structured log entry is written.

**FR-E07-037** — The command is read-only and safe to run concurrently with active postings. It MUST NOT hold long-running locks on `journal_lines`.

**FR-E07-038** — The command schedule cadence is TBD-E07-004; it is registered in the Artisan kernel and can be invoked manually at any time.

### Domain Events

**FR-E07-039** — All ledger mutations SHALL emit domain events via `OutboxEventPublisher` inside the same database transaction as the state change (constitution Principle 9). Domain events (PascalCase dot notation): `Ledger.Entry.Posted`, `Ledger.Entry.Reversed`, `Ledger.Account.Registered`, `Ledger.Imbalance.Detected`.

**FR-E07-040** — Event payloads SHALL include: `event_id` (UUID v7), `aggregate_id` (journal_id or account_id), `actor_id`, `correlation_id`, `occurred_at` (UTC ISO-8601), `schema_version`.

**FR-E07-041** — Event payloads MUST NOT contain PII. `owner_reference` (the owning entity UUID) is acceptable; no user name, email, or national ID may appear in ledger events.

### LedgerModuleContract and Cross-Module Interface

**FR-E07-042** — E07 SHALL provide a concrete implementation of `LedgerModuleContract` that replaces `NullLedgerModule` in the `LedgerServiceProvider` binding. The existing three methods SHALL be fully implemented: `postEntry(array $request): string`, `entryExists(string $transactionReference): bool`, `getBalance(string $ledgerAccountReference): int`.

**FR-E07-043** — `LedgerModuleContract` SHALL be expanded with two additional methods: `registerAccount(string $ledgerAccountReference, string $accountType, string $currencyCode, string $ownerReference, string $ownerType): void` and `getAccountHistory(string $ledgerAccountReference, int $page, int $perPage): array`. `NullLedgerModule` SHALL be updated to throw `ModuleNotImplementedException` for the new methods.

**FR-E07-044** — The `postEntry()` request array SHALL contain the following keys: `idempotency_key` (string, required), `entry_type` (string, required), `currency_code` (string, required), `transaction_reference` (string, nullable), `correlation_id` (string, required), `description` (string, nullable), `lines` (array, required — each element: `ledger_account_reference` (string), `direction` (string: `debit`|`credit`), `amount` (int, positive)). This structure is the internal service-to-service contract; callers within the monolith use it directly via the bound contract.

**FR-E07-045** — E06's `wallet.stub_ledger_balance` configuration key SHALL be removed from `phpunit.xml` environment after E07 is implemented and the `LedgerModuleContract` binding is live. The key remains valid as a developer override but MUST NOT be set in CI post-E07.

### Architecture Integrity

**FR-E07-046** — An architecture integrity test SHALL assert that no file outside `app/Modules/Ledger/` directly references `JournalModel`, `JournalLineModel`, or `LedgerAccountModel`; all cross-module access MUST go through `LedgerModuleContract`. Test placed at `tests/Architecture/Modules/LedgerBoundaryTest.php`.

**FR-E07-047** — Metrics SHALL be emitted for: `ledger.entries.posted` (counter, tagged by `entry_type`), `ledger.balance.query.duration` (histogram, milliseconds), `ledger.imbalance.detected` (counter), `ledger.reversals.posted` (counter).

---

## 8. Domain Model

### 8.1 Entity: LedgerAccount

| Field | Type | Description |
|---|---|---|
| `id` | UUID v7 | System identifier |
| `ledger_account_reference` | varchar(255) | Opaque reference pre-assigned by consuming module (e.g., E06 pocket creation); globally unique |
| `account_type` | enum | `asset`, `liability`, `income`, `expense`, `equity` |
| `normal_balance` | enum | `debit` (asset/expense) or `credit` (liability/income/equity) |
| `currency_code` | varchar(10) | ISO 4217 code |
| `owner_reference` | UUID | Opaque reference to owning entity (e.g., wallet pocket UUID) |
| `owner_type` | varchar(50) | `customer_pocket`, `system_float`, `system_revenue`, `system_fee`, `agent_commission`, `system_suspense` |
| `status` | enum | `active`, `closed` |
| `created_at` | timestamptz | UTC |
| `updated_at` | timestamptz | UTC |
| `created_by` | UUID | Actor ID |

**Constraints**: `ledger_account_reference` is globally unique.

### 8.2 Entity: Journal

| Field | Type | Description |
|---|---|---|
| `id` | UUID v7 | System identifier |
| `idempotency_key` | varchar(255) | Unique; used for duplicate detection |
| `entry_type` | enum | `debit`, `credit`, `fee`, `revenue`, `commission`, `reversal` |
| `currency_code` | varchar(10) | ISO 4217; all lines must match |
| `status` | enum | `posted` (write-once) |
| `transaction_reference` | varchar(255) | Nullable; cross-module reference (e.g., E11 transaction ID) |
| `reversed_journal_id` | UUID | Nullable; set on reversal journals to link to original |
| `correlation_id` | varchar(255) | Request correlation ID |
| `description` | text | Nullable; human-readable reason |
| `posted_at` | timestamptz | UTC timestamp of posting |
| `created_by` | UUID | Actor ID |

**Constraints**: `idempotency_key` is unique. `reversed_journal_id` is a self-referential FK.

### 8.3 Entity: JournalLine

| Field | Type | Description |
|---|---|---|
| `id` | UUID v7 | System identifier |
| `journal_id` | UUID | FK → `journals.id` |
| `ledger_account_id` | UUID | FK → `ledger_accounts.id` |
| `direction` | enum | `debit` or `credit` |
| `amount` | bigint | Positive integer; minor currency units |
| `currency_code` | varchar(10) | Must match parent journal `currency_code` |
| `created_at` | timestamptz | UTC |

**Constraint**: Immutable after insert. No `updated_at` column (deliberate — signals append-only). `SUM(amount WHERE direction='debit') == SUM(amount WHERE direction='credit')` per `journal_id` enforced via application-layer pre-insert check and optionally by a database constraint.

---

## 9. Double-Entry Architecture

### 9.1 Account Types and Normal Balances

| Account Type | Normal Balance | Balance Formula | Examples in OleKard |
|---|---|---|---|
| `liability` | credit | SUM(credits) − SUM(debits) | Customer wallet pockets (platform owes customer) |
| `income` | credit | SUM(credits) − SUM(debits) | Platform fee income, revenue |
| `equity` | credit | SUM(credits) − SUM(debits) | Retained earnings |
| `asset` | debit | SUM(debits) − SUM(credits) | Platform float / treasury |
| `expense` | debit | SUM(debits) − SUM(credits) | Commission expense |

### 9.2 Journal Flow Examples

**Wallet Funding (BWP 100 deposit)**:
| Line | Account | Direction | Amount |
|---|---|---|---|
| 1 | Platform Float (asset) | debit | 10,000 thebe |
| 2 | Customer Pocket (liability) | credit | 10,000 thebe |

**Customer Spend (BWP 50 payment)**:
| Line | Account | Direction | Amount |
|---|---|---|---|
| 1 | Customer Pocket (liability) | debit | 5,000 thebe |
| 2 | Merchant/Recipient Pocket (liability) | credit | 5,000 thebe |

**Fee Collection (BWP 2 fee)**:
| Line | Account | Direction | Amount |
|---|---|---|---|
| 1 | Customer Pocket (liability) | debit | 200 thebe |
| 2 | Platform Fee Income (income) | credit | 200 thebe |

Every journal: SUM(debit amounts) = SUM(credit amounts).

### 9.3 Invariant

At all times, for each distinct `currency_code`: `SUM(debit lines) == SUM(credit lines)` across all journals in that currency. Summing amounts across currencies is financially meaningless and never performed. This per-currency invariant is verified by `ledger:check-balance`.

---

## 10. Balance Computation

The authoritative balance for a ledger account is always computed from journal lines:

```
For normal_balance = credit (customer pockets, income, liability accounts):
  balance = SUM(amount WHERE direction = 'credit') − SUM(amount WHERE direction = 'debit')

For normal_balance = debit (asset, expense accounts):
  balance = SUM(amount WHERE direction = 'debit') − SUM(amount WHERE direction = 'credit')
```

**Pre-E07 stub mode** (E06 compatibility): `wallet.stub_ledger_balance = true` in `phpunit.xml` returns `ledger_balance = 0` with `meta.ledger_unavailable = true`. This flag is removed from production/CI after E07 is live (FR-E07-045).

---

## 11. Journal Lifecycle

```
Request received
     │
     ▼
Idempotency check ─── key exists ──→ return original response (HTTP 200)
     │
     │ new key
     ▼
Validate: balanced, currency match, amount > 0
     │
     │ invalid ──→ HTTP 422 (UNBALANCED_JOURNAL / CURRENCY_MISMATCH / INVALID_AMOUNT)
     ▼
Check accounts (auto-register unknown references)
     │
     ▼
Begin DB transaction
     │
     ├─ Insert Journal (status = posted)
     ├─ Insert JournalLines
     ├─ Insert OutboxEvent (Ledger.Entry.Posted)
     ├─ Store IdempotencyRecord
     └─ Commit atomically
          │
          ▼
     HTTP 201 (journal_id, posted_at)
```

A rollback at any step leaves no partial state; the `Idempotency-Key` is not consumed on rollback.

---

## 12. Security Requirements

**SR-E07-001** — All ledger API endpoints MUST require a valid Bearer token; unauthenticated requests return HTTP 401.

**SR-E07-002** — Authorization is server-side, enforced via `identity.require-permission` middleware (E04); client-provided authorization hints are never trusted.

**SR-E07-003** — Account identifier IDOR prevention: a caller without `ledger.read.any` requesting entries or balance for an account they do not own SHALL receive HTTP 404 (not 403) to prevent information disclosure.

**SR-E07-004** — All posting, reversal, and account registration endpoints MUST require an `Idempotency-Key` header; requests without it return HTTP 422.

**SR-E07-005** — `JournalLine` immutability is enforced at the repository layer: `save()` on an existing model throws `LogicException`; `delete()` always throws `LogicException`. No Admin API endpoint exposes an update or delete path for journal lines.

**SR-E07-006** — Reversal requires the `ledger.entry.reverse` permission; this permission is not assigned to Customer, Agent, or standard Admin roles by default.

**SR-E07-007** — Ledger event payloads and journal records MUST NOT include PII (no name, email, national ID, phone number). `owner_reference` is an opaque UUID; only structural financial data is stored in the ledger schema.

**SR-E07-008** — All postings, reversals, account registrations, and imbalance detections MUST produce an entry in `platform.audit_events` with: `action`, `actor_id`, `resource_type = journal` or `ledger_account`, `resource_id`, `metadata` (JSON with context), `correlation_id`, `occurred_at`.

**SR-E07-009** — Service-to-service postings (e.g., E06 Wallet service posting via `LedgerModuleContract`) use a service identity credential with `actor_type = SYSTEM`; the `created_by` field on the journal records the service actor ID.

**SR-E07-010** — Concurrent idempotency: if two requests with the same `Idempotency-Key` arrive simultaneously, the unique constraint on `journals.idempotency_key` ensures exactly one journal is created; the second is returned as an idempotent replay.

---

## 13. Concurrency Requirements

**CC-E07-001** — Two concurrent posts affecting the same account MUST result in correct sequential balance updates; no double-counting or lost updates. The database-level unique constraint on `idempotency_key` and row-level locking (where needed for balance enforcement in E06) serialize correctly.

**CC-E07-002** — Balance queries issued concurrently with active postings MUST return either the pre-posting or post-posting committed state; dirty reads are never permitted. `READ COMMITTED` isolation is the minimum required.

**CC-E07-003** — Concurrent duplicate posts with the same `Idempotency-Key` MUST result in exactly one `Journal` record. The unique DB constraint on `journals.idempotency_key` enforces this; the second INSERT fails with a unique violation and is caught and returned as an idempotent replay.

**CC-E07-004** — Concurrency tests MUST be present in `tests/Concurrency/Modules/Ledger/` covering at minimum: simultaneous posts to the same account, simultaneous duplicate idempotency key submissions, and concurrent balance query with active posting.

---

## 14. API Endpoints

### 14.1 Endpoints

| Method | Path | Description | Permission | Idempotency-Key |
|---|---|---|---|---|
| `POST` | `/v1/ledger/accounts` | Register a ledger account | `ledger.account.register` | Required |
| `POST` | `/v1/ledger/entries/debit` | Post a debit journal entry | `ledger.post` | Required |
| `POST` | `/v1/ledger/entries/credit` | Post a credit journal entry | `ledger.post` | Required |
| `POST` | `/v1/ledger/entries/fee` | Post a fee journal entry | `ledger.post` | Required |
| `POST` | `/v1/ledger/entries/revenue` | Post a revenue journal entry | `ledger.post` | Required |
| `POST` | `/v1/ledger/entries/commission` | Post a commission journal entry | `ledger.post` | Required |
| `POST` | `/v1/ledger/entries/{id}/reverse` | Reverse a posted journal | `ledger.entry.reverse` | Required |
| `GET` | `/v1/ledger/accounts/{reference}/balance` | Get computed balance | `ledger.read` | — |
| `GET` | `/v1/ledger/accounts/{reference}/entries` | Paginated account entry history | `ledger.read` | — |
| `GET` | `/v1/ledger/journals/{id}` | Get a specific journal with lines | `ledger.read` | — |
| `GET` | `/v1/ledger/journals` | Query journals by transaction_reference | `ledger.read` | — |

All endpoints: `/v1` prefix, Bearer auth, platform response envelope (`success`, `data`, `error`, `correlation_id`), UTC timestamps, UUID v7 identifiers.

### 14.2 Error Codes

| Code | HTTP Status | Description |
|---|---|---|
| `UNBALANCED_JOURNAL` | 422 | SUM(debits) ≠ SUM(credits) in the submitted journal |
| `CURRENCY_MISMATCH` | 422 | Journal lines contain mixed currencies |
| `INVALID_AMOUNT` | 422 | Amount is zero, negative, or non-integer |
| `ACCOUNT_NOT_FOUND` | 404 | `ledger_account_reference` not found |
| `ACCOUNT_CLOSED` | 422 | Account is closed; no further postings accepted |
| `JOURNAL_NOT_FOUND` | 404 | Journal ID not found |
| `JOURNAL_ALREADY_REVERSED` | 422 | Journal has already been reversed |
| `MISSING_IDEMPOTENCY_KEY` | 422 | `Idempotency-Key` header absent on a mutation endpoint |
| `LEDGER_MODULE_UNAVAILABLE` | 503 | Ledger service is temporarily unavailable |
| `INSUFFICIENT_PERMISSIONS` | 403 | Actor lacks required ledger permission |

### 14.3 Pagination

List endpoints accept `page` (default 1) and `per_page` (default 20, max 100) query params. Response `meta` object includes `total`, `page`, `per_page`, `pages`.

---

## 15. Permissions

**FR-E07-042 (detail)**:

| Permission | Description | Default Roles |
|---|---|---|
| `ledger.post` | Post journal entries | Admin, System |
| `ledger.read` | Read own account balance and history | Admin, System |
| `ledger.read.any` | Read any account balance and history | Admin |
| `ledger.account.register` | Register a new ledger account | Admin, System |
| `ledger.entry.reverse` | Reverse a posted journal | Privileged Admin only |

Customers and Agents do NOT have direct ledger API permissions. Their balances are surfaced via E06 Wallet API which calls `LedgerModuleContract` internally.

---

## 16. LedgerModuleContract Interface

E07 implements the concrete binding for `LedgerModuleContract`. The interface after E07 expansion:

```php
interface LedgerModuleContract
{
    // Existing (from E06):
    public function postEntry(array $request): string;
    public function entryExists(string $transactionReference): bool;
    public function getBalance(string $ledgerAccountReference): int;

    // New in E07:
    public function registerAccount(
        string $ledgerAccountReference,
        string $accountType,        // 'liability', 'asset', 'income', 'expense', 'equity'
        string $currencyCode,
        string $ownerReference,
        string $ownerType
    ): void;

    public function getAccountHistory(
        string $ledgerAccountReference,
        int $page,
        int $perPage
    ): array;   // returns paginated line records per platform convention
}
```

`postEntry()` request array structure (FR-E07-044):
```
[
  'idempotency_key'       => string (required),
  'entry_type'            => string (required: debit|credit|fee|revenue|commission),
  'currency_code'         => string (required),
  'transaction_reference' => string|null,
  'correlation_id'        => string (required),
  'description'           => string|null,
  'lines'                 => [
    ['ledger_account_reference' => string, 'direction' => 'debit'|'credit', 'amount' => int],
    ...
  ]
]
```

---

## 17. Domain Events

| Event | Trigger | Key Payload Fields |
|---|---|---|
| `Ledger.Entry.Posted` | Journal committed | `journal_id`, `entry_type`, `currency_code`, `transaction_reference`, `actor_id` |
| `Ledger.Entry.Reversed` | Reversal committed | `journal_id` (reversal), `reversed_journal_id` (original), `actor_id` |
| `Ledger.Account.Registered` | Account registered (explicit or auto) | `ledger_account_reference`, `account_type`, `currency_code`, `owner_type` |
| `Ledger.Imbalance.Detected` | `ledger:check-balance` finds discrepancy | `debit_total`, `credit_total`, `discrepancy`, `detected_at` |

All events include: `event_id` (UUID v7), `aggregate_id`, `actor_id`, `correlation_id`, `occurred_at`, `schema_version`.

---

## 18. Audit Requirements

All of the following operations MUST produce a `platform.audit_events` entry:

| Operation | `action` slug | `resource_type` |
|---|---|---|
| Journal posted | `ledger.entry.posted` | `journal` |
| Journal reversal posted | `ledger.entry.reversed` | `journal` |
| Account registered | `ledger.account.registered` | `ledger_account` |
| Imbalance detected | `ledger.imbalance.detected` | `ledger` |
| Balance query (admin) | `ledger.balance.read` | `ledger_account` |
| Unauthorized access attempt | `ledger.idor.blocked` | `ledger_account` |

> **Scope note**: SR-E07-008 governs the mandatory E07 audit obligation: postings, reversals, account registrations, and imbalance detections. The two read-surface events (`ledger.balance.read`, `ledger.idor.blocked`) are included in the table as future-ready slots; their implementation and testing are deferred to E19 (Admin Portal) and E23 (Audit & Compliance), where the Admin read-surface audit infrastructure will be built. No E07 task covers them and no E07 test asserts them.

---

## 19. Admin UI

E07 is a service-to-service API; no dedicated Admin screens are introduced in E07. Admin ledger read views (journal lookup by reference, account balance history, audit trail for compliance) are part of E19 Admin Portal and E23 Audit & Compliance. E07 specifies the API endpoints that those screens will consume.

---

## 20. Success Criteria

**SC-E07-001** — At no point in time does any journal have `SUM(debit lines) ≠ SUM(credit lines)`; this is verified by `ledger:check-balance` producing exit code 0 on a clean ledger.

**SC-E07-002** — `LedgerModuleContract::getBalance(R)` returns exactly `SUM(credits) − SUM(debits)` for all posted lines on account `R` for any normal-credit account; verified by posting N journals and asserting the balance.

**SC-E07-003** — Idempotent posting: any number of replays of the same `Idempotency-Key` produce exactly one `Journal` record; verified by posting the same request 10× and counting journal rows.

**SC-E07-004** — `JournalLine` immutability: no application code path can update or delete a posted line; verified by the architecture integrity test and the model-level `LogicException` tests.

**SC-E07-005** — Reversal produces a new journal with equal and opposite lines; the original journal is unchanged; `getBalance()` on affected accounts returns the correct net value after both journals.

**SC-E07-006** — All six baseline currencies (BWP, USD, EUR, ZAR, CNY, USDT) can be posted without currency-specific code paths; precision is resolved from the `currencies` table.

**SC-E07-007** — Concurrent posting test: (a) 10 concurrent posts with the same `Idempotency-Key` produce exactly one `Journal` record; the other 9 are returned as idempotent replays (verifies CC-E07-003). (b) 10 concurrent posts with distinct `Idempotency-Key` values to the same account all commit; `getBalance()` reflects the exact aggregate; no double-counting; `ledger:check-balance` passes with no imbalance.

**SC-E07-008** — `LedgerModuleContract` implementation is live: E06's `WalletBalanceService` returns real ledger balances without `wallet.stub_ledger_balance = true`; end-to-end balance test passes.

**SC-E07-009** — Every posting and reversal produces a `platform.audit_events` entry; every posting emits a `Ledger.Entry.Posted` outbox event in the same DB transaction; verified by the outbox rollback test.

**SC-E07-010** — Architecture integrity test `LedgerBoundaryTest` passes: no module outside `app/Modules/Ledger/` references `JournalModel`, `JournalLineModel`, or `LedgerAccountModel` directly.

**SC-E07-011** — E06's `wallet.stub_ledger_balance` flag is absent from `phpunit.xml` in the CI environment post-E07; all existing E06 balance tests pass against the real `LedgerModuleContract` implementation.

**SC-E07-012** — Balance query latency SLA is TBD-E07-007 and cannot be asserted in automated tests until resolved. The E07 obligation is instrumentation only: `ledger.balance.query.duration` histogram MUST be emitted on every `getBalance()` call (verified by T087). Latency alerting threshold and pass/fail assertion are explicitly deferred to TBD-E07-007 resolution; SC-E07-012 is satisfied at E07 release by metric presence alone.

---

## 21. Edge Cases

- **EC-E07-01**: Journal with `SUM(debit) ≠ SUM(credit)` → HTTP 422 `UNBALANCED_JOURNAL`; zero lines persisted; outbox event not emitted.
- **EC-E07-02**: Posting to a `closed` account → HTTP 422 `ACCOUNT_CLOSED`.
- **EC-E07-03**: `postEntry()` with unknown `ledger_account_reference` → account auto-registered as `liability/credit` provisional default (pending TBD-E07-002); posting succeeds.
- **EC-E07-04**: Duplicate post with same `Idempotency-Key` → original response returned; no second `Journal`.
- **EC-E07-05**: Race condition: two identical posts arrive simultaneously → unique constraint on `idempotency_key` ensures exactly one journal; second caller receives the idempotent replay response.
- **EC-E07-06**: Reversal targeting a journal that cannot be reversed → HTTP 422 `JOURNAL_ALREADY_REVERSED`. Covers two distinct sub-cases: (a) attempting to reverse J1 a second time when a reversal journal J2 already exists pointing to J1 (FR-E07-029); (b) attempting to reverse J2 itself — a reversal journal is immutable and may not be re-reversed (FR-E07-031). Both sub-cases throw `JournalAlreadyReversedException` and return the same error code.
- **EC-E07-07**: `getBalance()` on account with no entries → returns 0.
- **EC-E07-08**: `getBalance()` on unknown reference → returns 0 (not an error; account has no history).
- **EC-E07-09**: DB transaction rollback mid-posting → journal header, all lines, idempotency record, and outbox event all roll back; `Idempotency-Key` is re-usable after rollback.
- **EC-E07-10**: `ledger:check-balance` detects imbalance → emits `Ledger.Imbalance.Detected` event; logs CRITICAL; exits non-zero; no auto-correction; no ledger mutation.

---

## 22. TBDs

| ID | Description | Impact if Unresolved |
|---|---|---|
| TBD-E07-001 | System account structure: naming convention and initial set (float, suspense, revenue, fee, commission accounts); `ledger_account_reference` values for each system account | E08 Funding and E09 Transfers cannot post entries until system accounts are defined; no amount can be posted without knowing both debit and credit account references |
| TBD-E07-002 | Account type for customer wallet pockets: `liability` (platform owes customer, normal-credit) vs. `asset` (customer holds asset, normal-debit) | Affects balance formula direction and the sign of `getBalance()`; must be resolved before E08; spec defaults to `liability` (normal-credit) consistent with E06's `SUM(credits) − SUM(debits)` formula |
| TBD-E07-003 | Running balance materialization: store `current_balance` on `ledger_accounts` for query performance vs. always compute from lines | At high volume, computing balance from all lines may be slow; decision affects schema, constitution Principle 4 compliance, and caching strategy |
| TBD-E07-004 | Schedule cadence for `ledger:check-balance` Artisan command | Command registered but run frequency undefined; imbalances may go undetected until resolved |
| TBD-E07-005 | Reversal authorization: maker-checker workflow threshold and approval flow for `ledger.entry.reverse` operations | Reversals proceed with single-actor authorization until resolved; privileged permission remains the guard |
| TBD-E07-006 | Ledger entry data retention period for `journal_lines` and `journals` | No archival or purge job can be scheduled until resolved; `journal_lines` grows unboundedly |
| TBD-E07-007 | Balance query latency SLA: target P99 for `LedgerModuleContract::getBalance()` | Metric is instrumented but no alerting threshold can be set until resolved |

All TBD values MUST be represented in `platform.configuration_values` with `is_tbd = true`. No TBD value may be invented or hard-coded in domain logic.

---

## 23. Integration Boundaries

### What E07 Owns

| Concern | Owner |
|---|---|
| `LedgerAccount` registration, lifecycle | E07 |
| `Journal` and `JournalLine` entities | E07 |
| Balance computation from journal lines | E07 |
| `LedgerModuleContract` implementation | E07 |
| `ledger:check-balance` command | E07 |
| Ledger domain events | E07 |
| `ledger` PostgreSQL schema | E07 |

### What E07 Consumes from Prior Epics

| Dependency | Interface | Available |
|---|---|---|
| Bearer token auth | `identity.auth` middleware | ✅ E01 |
| Permission enforcement | `identity.require-permission` middleware | ✅ E04 |
| Idempotency service | `IdempotencyService` (PostgreSQL + Redis) | ✅ Platform Foundation |
| Audit events | `AuditService` / `platform.audit_events` | ✅ Platform Foundation |
| Outbox events | `OutboxEventPublisher` / `platform.outbox_events` | ✅ Platform Foundation |
| Currency metadata | `currencies` table (E06 schema) | ✅ E06 |
| `ledger_account_reference` pre-assignment | UUID v7 assigned by E06 at pocket creation | ✅ E06 |

### What E07 Does NOT Own

- Wallet holds (E06)
- Transaction orchestration (E11)
- FX / exchange rates (E29)
- Payment provider settlement (future epics)
- Reconciliation (E20)
- Fee rate calculation (E21)
- Commission schedule calculation (E14)

---

## 24. Epic-Level Definition of Done

- All 14 stories in this epic meet their story-specific Definition of Done.
- `LedgerModuleContract` concrete implementation is bound in `LedgerServiceProvider`; `NullLedgerModule` is no longer the active binding.
- E06's `WalletBalanceService` returns real ledger balances; `wallet.stub_ledger_balance` removed from CI environment.
- All posting operations enforce balanced-journal invariant; `JournalLine` model prevents update/delete.
- `ledger:check-balance` command is registered and verified with a clean-ledger test.
- Unit, feature, integration, security, concurrency, and architecture tests pass.
- `tests/Architecture/Modules/LedgerBoundaryTest.php` passes.
- All `platform.audit_events` entries present for all posting/reversal/registration operations.
- All outbox events emitted within the same DB transaction as state mutations.
- PHPStan level 8 passes over `app/Modules/Ledger/`.
- No unresolved CRITICAL or HIGH defect.
- No TBD value invented or hard-coded.
- **E08 Advance Gate — TBD-E07-001**: System account structure (naming convention and initial `ledger.system.*` key set) MUST be resolved and the seeder migration comment plus `config/ledger.php` stub updated with confirmed names before E08 implementation begins. E08 cannot post entries without knowing both debit and credit account references.
- **E08 Advance Gate — TBD-E07-002**: Customer wallet pocket `account_type` (`liability` vs `asset`) MUST be confirmed before E08 implementation begins. The E07 provisional default is `liability / normal_balance = credit`. If the decision differs, consuming modules that relied on auto-registration must be updated and an ADR must be recorded before E08 spec work starts.
- `checklists/requirements.md` all items checked.
