# Data Model: P01 — Architecture Contracts and Accounting Decisions

**Date**: 2026-10-21

This document defines the structure of each register artifact that P01 produces. These are documentation entities, not database tables.

---

## 1. Module Boundary Entry

Each entry in the module boundary register represents one application module.

```
ModuleBoundary:
  name: string                     # PascalCase module name, e.g. "Payment"
  namespace: string                # PHP namespace, e.g. "App\Modules\Payment"
  schema_prefix: string            # PostgreSQL schema or table prefix, e.g. "payment."
  has_dedicated_schema: boolean    # true if module owns a PostgreSQL schema
  owned_tables: string[]           # List of schema.table_name entries
  outbound_interfaces: Interface[] # Interfaces this module EXPOSES to other modules
  permitted_callers: string[]      # Modules that MAY call this module's interface(s)
  prohibited_direct_access: string[] # Other modules MUST NOT access these tables directly
```

```
Interface:
  name: string         # Interface class name
  path: string         # Relative PHP path
  consumers: string[]  # Which modules may call this interface
```

**Validation rules**:
- `owned_tables` must list `schema.table` format, not just `table`
- `outbound_interfaces` is empty for modules that expose no cross-module interface (internal only)
- `prohibited_direct_access` is derived: any table in `owned_tables` is prohibited to all modules not in `permitted_callers`

---

## 2. Contract Entry

Each entry in the contract register represents one approved cross-module interface.

```
ContractEntry:
  name: string                    # Interface class name
  php_path: string                # Relative path in repository
  owner_module: string            # Which module provides the implementation
  consumers: string[]             # Which modules are permitted to call this interface
  stability: "stable" | "evolving" # stable = methods MUST NOT be removed without ADR
  methods: MethodEntry[]
```

```
MethodEntry:
  name: string
  signature_summary: string       # Plain-language signature (not PHP syntax)
  preconditions: string[]         # MUST be true before calling
  postconditions: string[]        # MUST be true after successful call
  invariants: string[]            # Business rules that always hold
  error_conditions: string[]      # Named exceptions and when they are thrown
```

**Validation rules**:
- Every method on a `stability: "stable"` interface must have explicit preconditions and postconditions
- No method may mutate data owned by another module's schema

---

## 3. Financial Vocabulary Term

Each entry in the financial vocabulary glossary represents one lifecycle term.

```
VocabularyTerm:
  term: string                  # e.g. "Capture"
  definition: string            # One-sentence plain English
  trigger: string               # What causes this state/event
  preconditions: string[]       # What must be true before this occurs
  evidence_requirement: string  # What evidence is required to assert this has occurred
  event_emitted: string | null  # Integration event name, or null if none
  sequencing: string            # Where this term sits in the full lifecycle
  not_to_be_confused_with: string[]  # Common misinterpretations
  status: "confirmed" | "unresolved"
```

**Validation rules**:
- `evidence_requirement` must be explicit — "provider callback or synchronous provider confirmation" not "payment received"
- `event_emitted` must name a real event class from the codebase or null
- `status: "unresolved"` requires a `decision_owner` field with a named role

---

## 4. Accounting Decision Entry

Each entry in ADR-E48-003 represents one financial policy.

```
AccountingDecision:
  id: string                        # e.g. "AD-01"
  topic: string                     # e.g. "Custody account type"
  status: "confirmed" | "unresolved" | "awaiting_document"
  # When confirmed:
  confirmed_mapping: string | null  # The specific confirmed rule
  source_evidence: string[]         # ADR, code file, or document section
  # When unresolved/awaiting_document:
  decision_owner: string | null     # Named role (Finance Lead, Architect, etc.)
  risk_if_deferred: string | null   # Business/technical risk
  blocking_condition: string | null # What must happen to resolve
  # Common fields:
  affected_p_ids: string[]          # Which downstream P-IDs are blocked by this
```

**Validation rules**:
- No entry may have `status: "confirmed"` without at least one entry in `source_evidence`
- `source_evidence` MUST cite a real file path or ADR identifier — not the architecture document section alone (since that document is not in the repository)
- Every entry with `status: "unresolved"` or `"awaiting_document"` MUST have a named `decision_owner`
