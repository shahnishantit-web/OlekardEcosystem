# Spec Kit execution record
Feature: ADM-P1-020 / 044-integration-api-platform-management
Date: 2026-10-15

Specify: source catalogue and E25 spec reviewed with CLAUDE.md, constitution, master roadmap and related Admin018/019 and Ops code. Created spec and quality checklist.
Clarify: existing inbound callbacks, sole integrations.manage permission, empty allowlist compatibility and per-provider unknown observations resolved from source. Provider choice remains TBD.
Plan: setup-plan.ps1 -Json ran. Research agent integration_research confirmed reusable service/outbox, missing partner/IP fields, webhook races, and type-only health issue. Design artifacts and constitution gates completed.
Tasks: setup-tasks.ps1 -Json ran; 14 dependency-ordered tasks, story tests before implementation.
Analyze: read-only pre-implementation review found no uncovered requirement or constitution conflict. AC1–3/FR001 -> T003–005; AC4–6/FR002/007 -> T006–008; AC7–8/FR003 -> T009–011; FR004–006/SC001–003 -> T002,T012–014.
Implement: prerequisite/checklist gates passed; existing ignore patterns retained. Red tests proved missing migration/API. Added schema without seeds or role grants; reused services, renderer-free text, worker patterns and shared UI. Relevant backend and browser tests preserved.
Converge: final separate read-only source/artifact review; see convergence.md.
Hooks: .specify/extensions.yml absent, so hooks skipped under installed workflow.
Claude Code check returned HTTP429, weekly limit reset Oct16 9:30pm Asia/Calcutta. Proceeded under user's previously explicit Codex+SpecKit fallback choice and communicated this.
No commit, push, deployment or later epic was performed.

## Discovered defects resolved during implementation
- No IP storage/enforcement or partner association.
- No create-client form and stale nested response reads.
- No worker claim/completed/generation guard; manual and delayed retries could race.
- Unsigned callbacks accepted when verifier missing (contrary to E25 FR017/018); now401, valid signatures preserved.
- Invalid UUID 'none' when registering default provider; use generated integration UUID.
- Provider detail route shadowed call-logs; UUID constraints preserve call-log routes.
- Ops history was type-only; new identity-aware samples do not contaminate other partners.
- Collector failure branch omitted result assignment and lost integration attribution; regression now covers it.
- Provider errors could carry secret text; safe generic outcomes replace raw exception/probe error text in health logs/storage.

Legacy regression fixture corrections: actual /v1 routes, lowercase enum values, Platform audit table, shared validation envelope, dependency-injected job execution, signed test callback contract, and durable-retry-intent dispatch. Assertions continue to test original business outcomes.
