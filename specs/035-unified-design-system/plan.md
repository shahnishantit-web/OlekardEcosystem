# Implementation Plan: Unified Design System & Multi-Application Theming

**Branch**: `035-unified-design-system` | **Date**: 2026-10-10 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/035-unified-design-system/spec.md`

---

## Summary

Centralize the OleKard design token system by (1) updating the existing `packages/design-system` Style Dictionary source with all six approved application palettes, (2) wiring both Flutter apps to the shared generated Dart tokens, (3) replacing wrong-palette colors in Flutter ThemeData files, and (4) fixing the small number of remaining hardcoded colors in the admin app. The `packages/design-system` package, Style Dictionary pipeline, and admin CSS variable infrastructure already exist and are functional; no new tooling is introduced.

---

## Technical Context

**Language/Version**:
- Token pipeline: Node.js 20+ / Style Dictionary 3.x (already installed)
- Admin: Next.js 14 / TypeScript 5 / Tailwind CSS 3.4 / React 18
- Flutter apps: Flutter 3.19+ / Dart 3 (consumer + agent)

**Primary Dependencies**:
- `packages/design-system/`: Style Dictionary, existing JSON token source, build scripts
- Admin: `tailwindcss`, `next-themes` (already installed)
- Flutter: `flutter_test` (golden tests), path dependency on `packages/design-system`

**Storage**: N/A — no database changes

**Testing**:
- Token pipeline: `npm run tokens:build` + contrast validation script
- Admin: existing Jest/Vitest suite + new component snapshot tests
- Flutter: `flutter test` + new golden tests in `test/golden/`

**Target Platform**:
- Token source: build-time (Node.js)
- Admin: web (Chrome/Firefox/Safari desktop-first)
- Flutter: Android + iOS + web (dark-mode only)

**Project Type**: Cross-application design system — modifies styling/theming across three existing apps and one shared package

**Performance Goals**: N/A — styling changes have no throughput targets; build time for `npm run tokens:build` should remain under 30 seconds

**Constraints**:
- No changes to business logic, APIs, database, auth flows, RBAC
- No new shared Flutter package (ThemeData-only approach)
- Dark-mode only — no light-mode implementation
- Approved HEX values are immutable; derived accessible shades are documented

**Scale/Scope**:
- 1 shared package updated (`packages/design-system`)
- 1 web application themed (admin)
- 2 Flutter applications themed (customer, agent)
- 4 future theme definitions created (merchant, connect, ODSA, direct) — token definitions only, no UI
- ~15 admin UI components verified
- ~30+ Flutter screens across both apps

---

## Constitution Check

*GATE: Must pass before implementation. Re-checked after design artifacts.*

| Principle | Status | Notes |
|---|---|---|
| P1 — Source-of-Truth Requirements | ✅ PASS | No business intent changed; pure visual styling |
| P2 — TBD Governance | ✅ PASS | No TBD values; all approved HEX values specified in spec |
| P3 — Configuration Over Hard-Coding | ✅ PASS | Token system IS centralized configuration; enforces this principle |
| P4 — Financial Integrity | ✅ PASS | No ledger, wallet, or financial data touched |
| P5 — Identity and Authorization | ✅ PASS | No auth flows changed; no RBAC modified |
| P6 — Zero Trust and Security | ✅ PASS | No credentials or secrets involved |
| P7 — Domain Boundaries | ✅ PASS | No service boundaries changed; controllers untouched |
| P8 — API Contracts | ✅ PASS | No API endpoints created or modified |
| P9 — Event Reliability | ✅ PASS | No events emitted or consumed |
| P10 — UX and Design System | ✅ PASS | This feature directly fulfills P10: "Design tokens are the source of truth for visual implementation" |
| P11 — Accessibility | ✅ PASS | WCAG 2.2 AA requirements enforced via FR-025–FR-031 |
| P12 — Test-First Quality | ✅ PASS | Golden tests + snapshot tests + regression tests required |
| P13 — Observability | ✅ PASS | No changes to logging, metrics, or tracing |
| P14 — No Silent Architecture Changes | ✅ PASS | No stack changes; Style Dictionary already approved; no ADR needed |
| P15 — Release Governance | ✅ PASS | Standard release criteria apply |

**No violations. No complexity tracking entries needed.**

---

## Project Structure

### Documentation (this feature)

```text
specs/035-unified-design-system/
├── spec.md              # Feature specification
├── plan.md              # This file
├── research.md          # Phase 0 research decisions
├── data-model.md        # Token schema and data model
├── quickstart.md        # Validation scenarios
├── contracts/
│   └── token-contract.md  # Stable token surface contract
├── checklists/
│   └── requirements.md  # Quality checklist (36/36 pass)
└── tasks.md             # Phase 2 output (created by /speckit-tasks)
```

### Source Code (affected paths)

```text
wallet-ecosystem/
│
├── packages/design-system/          # PRIMARY CHANGE TARGET
│   ├── tokens/
│   │   ├── color.json               # UPDATE: add 6 approved app palettes + master brand
│   │   ├── spacing.json             # VERIFY: existing values match token contract
│   │   ├── typography.json          # VERIFY: existing values sufficient
│   │   ├── elevation.json           # VERIFY
│   │   ├── motion.json              # VERIFY
│   │   ├── radius.json              # VERIFY
│   │   └── breakpoints.json         # VERIFY
│   ├── style-dictionary.config.js   # UPDATE: add per-app theme output files
│   ├── build/
│   │   ├── css/
│   │   │   ├── tokens.css           # REGENERATED (main CSS output)
│   │   │   └── themes/              # NEW: per-app theme CSS files
│   │   │       ├── admin.css
│   │   │       ├── consumer.css
│   │   │       └── agent.css
│   │   └── dart/
│   │       └── tokens.dart          # REGENERATED (Dart output)
│   └── package.json                 # (no changes needed)
│
├── apps/admin/                      # MINIMAL CHANGES (already tokenized)
│   ├── styles/globals.css           # FIX: remove 3 hardcoded hex values in .status-pill
│   ├── tailwind.config.ts           # VERIFY: --ok-* variables reference correct teal values
│   └── components/ui/               # VERIFY: all 15 components use CSS variables (no edits expected)
│
├── apps/customer/                   # SIGNIFICANT CHANGES
│   ├── pubspec.yaml                 # ADD: design_system path dependency
│   ├── lib/core/theme/
│   │   ├── design_tokens.dart       # REWRITE: import OkTokens from shared package
│   │   └── app_theme.dart           # REWRITE: build ThemeData from Consumer Green tokens
│   ├── lib/core/theme/
│   │   └── ok_theme_extension.dart  # NEW: OkThemeExtension for consumer
│   ├── lib/modules/device/presentation/widgets/
│   │   └── device_status_badge.dart # FIX: replace 3 hardcoded Color(0x...) with token refs
│   └── test/golden/                 # NEW: golden test baseline captures
│       ├── login_screen_test.dart
│       ├── dashboard_screen_test.dart
│       └── wallet_screen_test.dart
│
└── apps/agent/                      # SIGNIFICANT CHANGES
    ├── pubspec.yaml                 # ADD: design_system path dependency
    ├── lib/core/theme/
    │   ├── design_tokens.dart       # REWRITE: import OkTokens from shared package
    │   └── app_theme.dart           # REWRITE: build ThemeData from Agent Blue tokens
    ├── lib/core/theme/
    │   └── ok_theme_extension.dart  # NEW: OkThemeExtension for agent
    └── test/golden/                 # NEW: golden test baseline captures
        ├── login_screen_test.dart
        ├── dashboard_screen_test.dart
        └── agent_operations_screen_test.dart
```

**Structure Decision**: The shared token package (`packages/design-system`) is the canonical source. Flutter apps receive token values as a path dependency; the admin app receives them via the existing CSS import. No application code is duplicated.

---

## Phase 0: Research ✅ COMPLETE

All decisions resolved. See `research.md` for full findings.

**Key resolved decisions**:
1. Token pipeline tool: Style Dictionary (already installed at `packages/design-system/`)
2. Token source: `color.json` — update with 6 approved application palettes
3. Flutter integration: path dependency + `OkTokens` class reuse + `OkThemeExtension`
4. Admin integration: already connected via CSS import; update variable values only
5. Multi-theme web: per-application CSS theme files generated by Style Dictionary
6. Visual regression: Flutter golden tests + admin component snapshot tests
7. Dark-mode only: confirmed — no light-mode implementation
8. Shared Flutter widgets: ThemeData/ThemeExtension only, no new package

---

## Phase 1: Design Artifacts ✅ COMPLETE

All design artifacts generated:

| Artifact | Path | Status |
|---|---|---|
| Token schema | `data-model.md` | ✅ Done |
| Token contract | `contracts/token-contract.md` | ✅ Done |
| Validation guide | `quickstart.md` | ✅ Done |

---

## Phase 2: Implementation (Planned — executed by /speckit-tasks + /speckit-implement)

### Implementation Order

**Phase A — Token Source Update (blocks everything)**
1. Update `packages/design-system/tokens/color.json` with all 6 approved application palettes and master brand tokens
2. Update `style-dictionary.config.js` to emit per-application theme CSS files
3. Run `npm run tokens:build` — verify CSS and Dart outputs are correct
4. Verify all token values in `build/dart/tokens.dart` and `build/css/tokens.css`

**Phase B — Admin App Update (independent of Flutter)**
1. Verify `styles/globals.css` imports admin theme CSS (update import path if needed)
2. Fix `.status-pill` hardcoded hex values in `globals.css` → use semantic status tokens
3. Verify `tailwind.config.ts` `--ok-*` variables map to correct teal CSS variables
4. Smoke-test admin app visually: sidebar, dashboard, badges, tables
5. Write component snapshot tests for all 15 `components/ui/` files

**Phase C — Consumer Flutter App (after Phase A)**
1. Add `design_system: { path: ../../packages/design-system }` to `pubspec.yaml`
2. Run `flutter pub get`
3. Rewrite `lib/core/theme/design_tokens.dart` to import `OkTokens` from shared package
4. Rewrite `lib/core/theme/app_theme.dart` to build `ThemeData` from Consumer Green tokens
5. Create `lib/core/theme/ok_theme_extension.dart` with `OkThemeExtension.consumer()` factory
6. Fix `device_status_badge.dart` — replace 3 hardcoded `Color(0x...)` with `OkThemeExtension` references
7. Run `flutter analyze` — must produce 0 issues
8. Run `flutter test` — all existing tests must pass
9. Write golden tests in `test/golden/` and capture baselines

**Phase D — Agent Flutter App (after Phase A; parallel with Phase C)**
1. Add `design_system` path dependency to `pubspec.yaml`
2. Run `flutter pub get`
3. Rewrite `lib/core/theme/design_tokens.dart` to import `OkTokens`
4. Rewrite `lib/core/theme/app_theme.dart` to build `ThemeData` from Agent Blue tokens
5. Create `lib/core/theme/ok_theme_extension.dart` with `OkThemeExtension.agent()` factory
6. Run `flutter analyze` — must produce 0 issues
7. Run `flutter test` — all existing tests must pass
8. Write golden tests and capture baselines

**Phase E — Accessibility & Cross-Cutting Validation**
1. Run contrast validation against the 8 foreground/background combinations in `quickstart.md` SC-08
2. Document any contrast adjustments required (FR-027)
3. Verify status indicators have non-color cues in all apps
4. Verify reduced-motion behavior in admin app (CSS `prefers-reduced-motion`)
5. Verify touch target sizes in Flutter apps (44×44 dp minimum)
6. Run full quickstart validation against all SC-## scenarios

---

## Constitution Check (Post-Design) ✅

No new violations identified after design artifacts. The implementation plan:
- Does not touch financial logic, ledger, or wallet balance code
- Does not modify API endpoints or contracts
- Does not alter authentication or RBAC
- Does not introduce new architectural patterns beyond what the constitution already endorses (ThemeExtension is idiomatic Flutter 3.x)
- Extends existing test infrastructure rather than replacing it
