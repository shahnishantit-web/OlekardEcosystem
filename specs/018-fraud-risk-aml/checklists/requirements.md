# Specification Quality Checklist: E18 — Fraud, Risk & AML

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-26
**Feature**: [spec.md](../spec.md)

## Content Quality

- [ ] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [ ] Written for non-technical stakeholders
- [x] All mandatory sections completed

> **Note**: This spec uses the project-standard backlog-decomposition format, which intentionally includes API endpoint paths, DB entities, platform stack declarations, and event names. Items marked [ ] above reflect this intentional deviation from the generic checklist template — they are not defects. All 22 user stories include the required fields (actor, acceptance criteria, API, DB entities, events, permissions, QA scenarios, DoD).

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [ ] Success criteria are measurable
- [ ] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [ ] Edge cases are identified
- [x] Scope is clearly bounded
- [ ] Dependencies and assumptions identified

> **Note**: Success criteria use Definition of Done (story-level pass/fail) rather than SC-NNN metric targets. No explicit performance/latency targets are defined in the spec — these should be captured in plan.md during `/speckit-plan`. Edge cases are not enumerated; they will surface during `/speckit-plan` research. Dependencies on E03, E08, E09, E10, E12, E14, E15, E16 are implied by the API/DB entity references but not explicitly listed.

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [ ] Feature meets measurable outcomes defined in Success Criteria
- [ ] No implementation details leak into specification

> **Note**: Measurable outcomes (latency, false-positive rate, throughput) are deferred to plan.md. Implementation detail presence is intentional per project format (see Content Quality note above).

## Story Coverage Summary

| Story ID | Title | Priority | Status |
|----------|-------|----------|--------|
| US-RISK-001 | Evaluate transaction risk | P0 | Specified |
| US-RISK-002 | Evaluate transaction velocity | P0 | Specified |
| US-RISK-003 | Evaluate amount threshold | P0 | Specified |
| US-RISK-004 | Evaluate device risk | P0 | Specified |
| US-RISK-005 | Evaluate IP risk | P1 | Specified |
| US-RISK-006 | Evaluate beneficiary risk | P1 | Specified |
| US-RISK-007 | Allow transaction | P0 | Specified |
| US-RISK-008 | Block transaction | P0 | Specified |
| US-RISK-009 | Send transaction for manual review | P0 | Specified |
| US-RISK-010 | Configure per-transaction limit | P0 | Specified |
| US-RISK-011 | Configure daily limit | P0 | Specified |
| US-RISK-012 | Configure monthly limit | P0 | Specified |
| US-RISK-013 | Configure customer limits | P0 | Specified |
| US-RISK-014 | Configure agent limits | P0 | Specified |
| US-RISK-015 | Configure wallet limits | P0 | Specified |
| US-RISK-016 | Detect unusual transaction pattern | P1 | Specified |
| US-RISK-017 | Detect rapid fund movement | P1 | Specified |
| US-RISK-018 | Detect repeated failed authentication | P0 | Specified |
| US-RISK-019 | Create risk alert | P0 | Specified |
| US-RISK-020 | Investigate risk alert | P0 | Specified |
| US-RISK-021 | Resolve risk alert | P0 | Specified |
| US-RISK-022 | Freeze account due to risk | P0 | Specified |

## Items Requiring Attention Before `/speckit-plan`

1. **Performance/latency targets** — Risk evaluation latency SLA not defined (e.g., max milliseconds per evaluation). Must be specified in plan.md research phase to guide implementation and load-test design.
2. **Cross-epic dependencies** — Explicit list of contracts consumed from E03 (device risk), E08–E16 (transaction events) needed in plan.md data-model and contracts sections.
3. **Edge cases** — No edge cases enumerated (e.g., evaluation service unavailable, structuring detection across historical window, concurrent rule updates). Capture in plan.md or as clarifications.
4. **AML regulatory scope** — No jurisdiction specified for regulatory reporting formats (e.g., Botswana FIA). Must be resolved in plan.md research — not invented here per constitution P2.
5. **Sync vs. async evaluation** — US-RISK-001 through US-RISK-009 do not specify whether risk evaluation is synchronous (blocking transaction) or asynchronous (post-processing). This is a P0 architectural decision for plan.md.
