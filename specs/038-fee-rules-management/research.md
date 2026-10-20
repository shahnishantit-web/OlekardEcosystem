# Research: ADM-P1-014 — Fee Rules Management

**Date**: 2026-10-14

## Decision 1 — API Client Pattern

**Decision**: Use `authenticatedClient` directly (from `useAuth()`) rather than an `AdminAppClient` wrapper.

**Rationale**: The existing `fee-rule-create.tsx` already uses `authenticatedClient.post('/v1/fees/rules', ...)`. Adopting `AdminAppClient` would require adding typed methods to that class for fee operations, touching a shared file unnecessarily. Staying consistent with the existing fee module pattern is simpler.

**Alternatives considered**: `AdminAppClient` wrapper (used in agents module) — rejected to avoid touching the shared client class and maintain consistency within the fees module.

---

## Decision 2 — Deactivation Endpoint

**Decision**: Use `DELETE /v1/fees/rules/{id}` for deactivation (HTTP 204).

**Rationale**: The E21 OpenAPI contract defines `DELETE /v1/fees/rules/{id}` as the deactivation endpoint (soft-delete, transitions status to `deactivated`). The admin catalogue planning note mentioned `/activate` and `/deactivate` sub-resources, but those do not exist in the implemented backend. The existing frontend already uses `/v1/fees/rules` (not `/v1/admin/fees/rules`), confirming the real endpoints.

**Alternatives considered**: PATCH with `{ status: 'deactivated' }` — rejected because the E21 `UpdateFeeRuleRequest` schema does not include a `status` field; only `valid_to`, `min_fee_minor`, and `max_fee_minor` are patchable.

---

## Decision 3 — Simulation Endpoint

**Decision**: Use `POST /v1/fees/calculate` for fee impact simulation.

**Rationale**: E21 OpenAPI contract (`fees-calculate.yaml`) defines this as a read-only, stateless fee preview endpoint. It does not create any records. The admin catalogue mentioned `/v1/fees/simulate` but that path is not in the backend contract.

**Alternatives considered**: None — only one fee calculation endpoint exists in E21.

---

## Decision 4 — Re-activation

**Decision**: There is no re-activation capability in this epic.

**Rationale**: The E21 FeeRule status lifecycle only allows `active → deactivated` (via DELETE) and `active → expired` (via PATCH valid_to to past). There is no backend endpoint to restore a deactivated rule. To replace a deactivated rule, the admin must create a new rule. The UI communicates this clearly on the deactivated rule detail page.

**Alternatives considered**: PATCH to restore — rejected because the backend does not support it per the E21 contract and data model.

---

## Decision 5 — Row Click Navigation

**Decision**: Modify `fee-rules-list.tsx` to add `onClick={() => router.push(`/fees/rules/${r.id}`)}` on each table row, plus `cursor-pointer` styling.

**Rationale**: The Table component renders plain `<tr>` rows with no built-in navigation. This is the same pattern used by other list pages (agents, wallets, transactions).

**Alternatives considered**: Link wrapper on the rule ID cell — less intuitive than full-row click; full-row click is consistent with the rest of the admin portal.

---

## Decision 6 — Simulation Panel Placement

**Decision**: Simulation panel is a collapsible section on the fee rule detail page, toggled by a "Simulate Fee Impact" button.

**Rationale**: Simulation is contextual to a specific rule — the panel pre-fills `transaction_type` and `currency` from the rule. Placing it on the detail page avoids a separate route and keeps the admin in context while testing a rule.

**Alternatives considered**: Separate `/fees/simulate` page — rejected as over-engineering for a single form interaction.

---

## Decision 7 — Error Message Mapping

**Decision**: Map known backend error codes to human-readable messages in the UI layer.

| API Code | User-Facing Message |
|----------|---------------------|
| `FEE_RULE_CONFLICT` | "A conflicting active fee rule already exists for this transaction type, currency, and subject scope." |
| `INVALID_FEE_RATE` | "Rate must be greater than 0 and no more than 100." |
| `INVALID_FEE_AMOUNT` | "Fixed fee amount must be greater than 0." |
| `MIN_FEE_NOT_APPLICABLE_TO_FIXED_RULES` | "Minimum/maximum fee bounds cannot be set on fixed-amount rules." |
| `FEE_BOUNDS_CONFLICT` | "Minimum fee cannot exceed maximum fee." |
| Generic 500/network | "An unexpected error occurred. Please try again." |

**Rationale**: Constitution Principle says zero raw API codes visible to users.
