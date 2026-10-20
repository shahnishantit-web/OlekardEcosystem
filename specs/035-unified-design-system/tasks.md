# Tasks: Unified Design System & Multi-Application Theming

**Input**: Design documents from `specs/035-unified-design-system/`

**Prerequisites**: plan.md ✅ | spec.md ✅ | research.md ✅ | data-model.md ✅ | contracts/ ✅ | quickstart.md ✅

**Organization**: Tasks grouped by user story for independent implementation and testing. Dark-mode only — no light-mode tasks.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no shared dependencies)
- **[Story]**: Which user story this task belongs to (US1–US6)

---

## Phase 1: Setup

**Purpose**: Verify the existing `packages/design-system` infrastructure is intact and understood before any changes begin.

- [X] T001 Read `wallet-ecosystem/packages/design-system/tokens/color.json`, `tokens/spacing.json`, `tokens/typography.json`, `tokens/elevation.json`, `tokens/radius.json`, `tokens/motion.json`, and `tokens/breakpoints.json` — confirm their current structure and record existing values for comparison against `contracts/token-contract.md` and `data-model.md`
- [X] T002 Read `wallet-ecosystem/packages/design-system/style-dictionary.config.js` and confirm the current build pipeline configuration (platforms, transforms, output files)
- [X] T003 [P] Read `wallet-ecosystem/packages/design-system/build/css/tokens.css` and list all existing `--ok-*` CSS custom property names currently defined
- [X] T004 [P] Read `wallet-ecosystem/packages/design-system/build/dart/tokens.dart` and confirm the current class/constant structure generated for Flutter
- [X] T005 [P] Read `wallet-ecosystem/apps/admin/styles/globals.css` and identify the current `@import` path for the token CSS file and the hardcoded `.status-pill` hex values to be replaced
- [X] T006 [P] Read `wallet-ecosystem/apps/customer/pubspec.yaml` and `wallet-ecosystem/apps/agent/pubspec.yaml` to confirm neither currently declares a `design_system` dependency

---

## Phase 2: Foundational (Blocking — must complete before all user story phases)

**Purpose**: Update the single canonical token source and regenerate all platform outputs. Every subsequent phase depends on the correct values being present in the built files.

⚠️ **CRITICAL**: No user story work can begin until T007–T017 are complete and the build passes.

- [X] T007 Update `wallet-ecosystem/packages/design-system/tokens/color.json`: add Consumer Green palette group with keys `primary` (#16A34A), `dark` (#15803D), `light` (#4ADE80), `surface` (#062A1A), `background` (#001B12), `accent` (#84CC16); set `text` to `#FFFFFF`
- [X] T008 Update `wallet-ecosystem/packages/design-system/tokens/color.json`: add Agent Blue palette group with keys `primary` (#2563EB), `dark` (#1D4ED8), `light` (#60A5FA), `surface` (#061B3A), `background` (#001329), `accent` (#38BDF8); set `text` to `#FFFFFF`
- [X] T009 Update `wallet-ecosystem/packages/design-system/tokens/color.json`: add Admin Teal palette group with keys `primary` (#0F766E), `dark` (#115E59), `light` (#2DD4BF), `surface` (#062A2A), `background` (#001A1D), `accent` (#14B8A6); set `text` to `#FFFFFF`
- [X] T010 Update `wallet-ecosystem/packages/design-system/tokens/color.json`: add Merchant Orange palette group (#F97316 primary, #C2410C dark, #FB923C light, #351407 surface, #1A0A03 background, #FBBF24 accent); Connect Purple (#7C3AED primary, #5B21B6 dark, #A78BFA light, #1A0B36 surface, #090016 background, #C084FC accent, #E879F9 aiGlow); ODSA Gold (#D4A017 primary, #8A6508 dark, #F6C453 light, #302000 surface, #120D00 background, #F5B82E accent)
- [X] T011 Update `wallet-ecosystem/packages/design-system/tokens/color.json`: add/update master brand group with keys `navy` (#001426), `midnight` (#000B18), `gold` (#F5B82E), `goldLight` (#FFC94A); update neutral group with `white` (#FFFFFF), `secondary` (#B8C4D0), `border` (#294052)
- [X] T012 Update `wallet-ecosystem/packages/design-system/tokens/color.json`: add/update shared semantic status group with `success` (#22C55E), `warning` (#F59E0B), `error` (#EF4444), `info` (#38BDF8); add bg-variant entries `successBg` (#052E16), `warningBg` (#1C1305), `errorBg` (#1A0505), `infoBg` (#041520); add border-variant entries `successBorder` (#15803D), `warningBorder` (#B45309), `errorBorder` (#B91C1C), `infoBorder` (#0369A1)
- [X] T013 Update `wallet-ecosystem/packages/design-system/tokens/color.json`: add service icon color group with `wallet` (#F5B82E), `payments` (#2563EB), `utilities` (#22C55E), `messaging` (#06B6D4), `ai` (#8B5CF6), `notifications` (#F59E0B), `security` (#10B981), `rewards` (#EC4899), `support` (#14B8A6), `multilanguage` (#3B82F6)
- [X] T014 Update `wallet-ecosystem/packages/design-system/style-dictionary.config.js`: extend the CSS platform to also emit per-application theme files at `build/css/themes/admin.css`, `build/css/themes/consumer.css`, `build/css/themes/agent.css`, `build/css/themes/merchant.css`, `build/css/themes/connect.css`, `build/css/themes/odsa.css`, each containing `--ok-*` variable mappings for that application's palette
- [X] T015 Before building: compare `tokens/spacing.json` against the contract scale (xs:4, sm:8, md:16, lg:24, xl:32, xxl:48) and update any mismatched values; compare `tokens/typography.json` against the `data-model.md` hierarchy (display, heading, body, caption, label) and add any missing levels; then run `npm run tokens:build` in `wallet-ecosystem/packages/design-system/` and confirm exit code 0 with no unresolved-reference warnings
- [X] T016 Read `wallet-ecosystem/packages/design-system/build/css/tokens.css` after build and verify that `--color-green-primary`, `--color-blue-primary`, `--color-teal-primary`, and all other tokens listed in `contracts/token-contract.md` are present with correct hex values
- [X] T017 Read `wallet-ecosystem/packages/design-system/build/dart/tokens.dart` after build and verify that `colorGreenPrimary`, `colorBluePrimary`, `colorTealPrimary`, and all Dart constants listed in `contracts/token-contract.md` are present with correct Color values

**Checkpoint**: Token source updated and build verified. All platform outputs contain correct values. User story phases may now begin.

---

## Phase 3: User Story 1 — Distinct App Identity (Priority: P1) 🎯 MVP

**Goal**: Every OleKard application uses its correct brand color family. Consumer = Green, Agent = Blue, Admin = Teal. A developer can open each application and immediately see the correct identity without navigating deep into the UI.

**Independent Test**: Open the login/entry screen of each application and confirm the primary color, background, and any visible CTA button matches the approved palette. No cross-contamination of colors between apps.

### Administration Platform — Apply Teal

- [X] T018 [US1] Update `wallet-ecosystem/apps/admin/styles/globals.css`: change the `@import` line to reference `../../../packages/design-system/build/css/themes/admin.css` so the admin-specific `--ok-*` variables (teal palette) are loaded
- [X] T019 [US1] Read `wallet-ecosystem/apps/admin/tailwind.config.ts` and verify that `ok.primary`, `ok.background`, `ok.surface`, `ok.accent`, `ok.nav-active` and related entries all reference the correct `--ok-*` CSS variables (not hardcoded hex values); update any entries that still use literal hex values
- [X] T020 [US1] Fix `wallet-ecosystem/apps/admin/styles/globals.css`: replace the three hardcoded hex values inside the `.status-pill` rule (`#e5f4eb`, `#a9d7b8`, `#17633a`) with `var(--color-status-success-bg)`, `var(--color-status-success-border)`, `var(--color-status-success)` — these are the primitive token names generated by Style Dictionary; both `--color-status-success-bg` and `--color-status-success-border` were added in T012

### Consumer Flutter App — Apply Consumer Green

- [X] T021 [US1] Add `design_system` as a local path dependency to `wallet-ecosystem/apps/customer/pubspec.yaml`: add `design_system: { path: ../../packages/design-system }` under `dependencies`
- [X] T022 [US1] Rewrite `wallet-ecosystem/apps/customer/lib/core/theme/design_tokens.dart`: replace the `AppColors` abstract class with an import of `OkTokens` from the shared package and re-export the Consumer Green constants (`primary = OkTokens.colorGreenPrimary`, `background = OkTokens.colorGreenBackground`, `surface = OkTokens.colorGreenSurface`, `accent = OkTokens.colorGreenAccent`, `success = OkTokens.colorStatusSuccess`, `warning = OkTokens.colorStatusWarning`, `error = OkTokens.colorStatusError`, `info = OkTokens.colorStatusInfo`); preserve `AppSpacing` and `AppTypography` classes, updating their values to reference `OkTokens` spacing and typography constants
- [X] T023 [US1] Create `wallet-ecosystem/apps/customer/lib/core/theme/ok_theme_extension.dart`: define `OkThemeExtension extends ThemeExtension<OkThemeExtension>` with fields `primary`, `primaryDark`, `primaryLight`, `background`, `surface`, `accent`, `navActiveColor`, `successBg`, `warningBg`, `errorBg`, `infoBg`; implement `OkThemeExtension.consumer()` factory that populates all fields from `OkTokens` Consumer Green + status tokens; implement `copyWith` and `lerp`
- [X] T024 [US1] Rewrite `wallet-ecosystem/apps/customer/lib/core/theme/app_theme.dart`: replace `ColorScheme.fromSeed()` (Google Blue seed) with `ColorScheme.dark()` using Consumer Green token values from `OkTokens`; set `useMaterial3: true`; attach `OkThemeExtension.consumer()` to `extensions`; rebuild `AppBarTheme`, `ElevatedButtonThemeData`, `CardTheme`, `InputDecorationTheme` using token-referenced colors

### Agent Flutter App — Apply Agent Blue (parallel with Consumer tasks)

- [X] T025 [P] [US1] Add `design_system` as a local path dependency to `wallet-ecosystem/apps/agent/pubspec.yaml`: add `design_system: { path: ../../packages/design-system }` under `dependencies`
- [X] T026 [P] [US1] Rewrite `wallet-ecosystem/apps/agent/lib/core/theme/design_tokens.dart`: replace `AppColors` constants (currently Material Blue-900 palette) with imports from `OkTokens` using Agent Blue values (`primary = OkTokens.colorBluePrimary`, `background = OkTokens.colorBlueBackground`, etc.); preserve `AppTypography` and `AppSpacing`, updating values from `OkTokens`; preserve float-balance-specific color constants (`floatNormal`, `floatLowAmber`, `floatCriticalRed`) mapping them to `OkTokens.colorStatusSuccess/Warning/Error`
- [X] T027 [P] [US1] Create `wallet-ecosystem/apps/agent/lib/core/theme/ok_theme_extension.dart`: define `OkThemeExtension.agent()` factory populating all fields from `OkTokens` Agent Blue + status tokens; share the same `OkThemeExtension` class structure as the consumer app
- [X] T028 [P] [US1] Rewrite `wallet-ecosystem/apps/agent/lib/core/theme/app_theme.dart`: rebuild `ThemeData` with `ColorScheme.dark()` using Agent Blue tokens; include the full theme coverage from the existing file (outlinedButtonTheme, textButtonTheme, bottomNavigationBarTheme, chipTheme, dividerTheme, full TextTheme); attach `OkThemeExtension.agent()`; remove the broken dark-theme stub (`copyWith(brightness: Brightness.dark)` with no actual dark values)

### Verification

- [X] T029 [US1] Run `flutter pub get` in `wallet-ecosystem/apps/customer/` and confirm no dependency resolution errors
- [X] T030 [P] [US1] Run `flutter pub get` in `wallet-ecosystem/apps/agent/` and confirm no dependency resolution errors
- [X] T031 [US1] Run `flutter analyze` in `wallet-ecosystem/apps/customer/` — must produce `No issues found!`
- [X] T032 [P] [US1] Run `flutter analyze` in `wallet-ecosystem/apps/agent/` — must produce `No issues found!`
- [X] T033 [US1] Run `flutter test` in `wallet-ecosystem/apps/customer/` — all existing tests must pass
- [X] T034 [P] [US1] Run `flutter test` in `wallet-ecosystem/apps/agent/` — all existing tests must pass

**Checkpoint**: Each application displays its correct brand color family. Consumer = Green (#16A34A). Agent = Blue (#2563EB). Admin = Teal (#0F766E). All Flutter tests pass.

---

## Phase 4: User Story 2 — Accessibility-Compliant Visual Design (Priority: P2)

**Goal**: Every text/background combination meets WCAG 2.2 AA contrast. Status indicators include non-color cues. Focus rings are visible. Touch targets are adequate.

**Independent Test**: Run the contrast checks in `quickstart.md` SC-08. Open each application and tab through interactive controls — focus ring must be clearly visible. Inspect status badges — each must have text or an icon alongside its color.

- [X] T035 [US2] Compute WCAG 2.2 AA contrast ratios for: (a) the 8 primary foreground/background text pairs in `quickstart.md` SC-08 (4.5:1 minimum); (b) 4 interactive control boundaries: admin button border on page background, admin input border on surface background, admin table header background on surface, admin focus ring on page background (3:1 minimum per FR-026); use `color-contrast` npm package or equivalent and document all 12 results with pass/fail in `specs/035-unified-design-system/accessibility-report.md`
- [X] T036 [US2] For any pair that fails 4.5:1 (most likely `#FFFFFF` on `#0F766E` ≈ 3.2:1): add a documented accessible foreground override per FR-027 — e.g., use `--ok-foreground-on-primary: #001A1D` for text on teal primary buttons; update the relevant CSS variable in `build/css/themes/admin.css` and add to `accessibility-report.md`; do NOT change the approved `--ok-primary` base color value
- [X] T037 [US2] Audit `wallet-ecosystem/apps/admin/components/ui/Badge.tsx` and any other status-indicator components: verify each variant (`success`, `warning`, `error`, `info`, `neutral`) renders a text label in addition to its background color; if any status is displayed as a color-only dot, add a visually meaningful label or icon per FR-029
- [X] T038 [US2] Audit `wallet-ecosystem/apps/customer/lib/modules/device/presentation/widgets/device_status_badge.dart`: verify the badge widget shows both a color and a text label for each status state (trusted, pending, blocked); if color-only, add a label per FR-029
- [X] T039 [US2] Add `@media (prefers-reduced-motion: reduce)` CSS block to `wallet-ecosystem/apps/admin/styles/globals.css` that sets `transition-duration: 0.01ms !important` and `animation-duration: 0.01ms !important` for all transitions and animations per FR-031
- [X] T040 [US2] Inspect admin component focus ring styles in `wallet-ecosystem/apps/admin/styles/globals.css` and `wallet-ecosystem/apps/admin/tailwind.config.ts`: verify that `:focus-visible` produces a ring that meets 3:1 contrast against adjacent backgrounds; add or update the `ring-*` / `outline` Tailwind utility usage in `components/ui/Button.tsx` and `components/ui/Input.tsx` per FR-028
- [X] T041 [US2] Audit `wallet-ecosystem/apps/customer/lib/core/theme/app_theme.dart` and `wallet-ecosystem/apps/agent/lib/core/theme/app_theme.dart`: verify `ElevatedButton.styleFrom` includes a `focusColor` or `overlayColor` that provides visible focus feedback; if absent, add `focusColor: OkTokens.colorGreenLight.withOpacity(0.3)` (consumer) / `OkTokens.colorBlueLight.withOpacity(0.3)` (agent) per FR-028
- [X] T042 [US2] Search `wallet-ecosystem/apps/customer/lib/` and `wallet-ecosystem/apps/agent/lib/` for any `GestureDetector`, `InkWell`, or `IconButton` whose `child` is smaller than 44×44 dp: replace with `SizedBox(width: 44, height: 44, child: ...)` wrapper or `IconButton` with explicit `constraints: BoxConstraints(minWidth: 44, minHeight: 44)` per FR-030

**Checkpoint**: All primary contrast pairs documented. Inaccessible pairs have a documented accessible override. Status indicators have non-color cues. Focus visible. Touch targets ≥ 44dp.

---

## Phase 5: User Story 3 — Centralized Design Token Governance (Priority: P3)

**Goal**: A developer can change one token value in `color.json`, run one build command, and verify the change propagated to both CSS output (admin) and Dart output (Flutter) without editing any other file.

**Independent Test**: Follow `quickstart.md` SC-04 — temporarily change `color.status.error` to `#FF0000`, rebuild, verify both `tokens.css` and `tokens.dart` reflect `#FF0000`, then revert.

- [X] T043 [US3] Write `wallet-ecosystem/packages/design-system/scripts/validate-tokens.js`: a Node.js script that reads `build/css/tokens.css` and `build/dart/tokens.dart` and asserts every token name listed in `contracts/token-contract.md` is present and non-empty; exits with code 1 if any are missing; add `"tokens:validate": "node scripts/validate-tokens.js"` to `packages/design-system/package.json`
- [X] T044 [US3] Add `"tokens:validate"` as a post-build step in `packages/design-system/package.json` so running `npm run tokens:build` automatically runs `npm run tokens:validate` afterwards; verify the validate script passes against the current built output
- [X] T045 [US3] Document the token governance workflow in `packages/design-system/README.md` (create if absent): how to add a new token (edit `tokens/color.json` → run `npm run tokens:build` → commit both source and generated files); how to use tokens in each platform (CSS import path, Flutter pubspec path, Dart class name); include a one-paragraph note that generated files in `build/` must be committed so they are available to Flutter without requiring a Node.js build step

**Checkpoint**: Token validation script passes. Any change to `color.json` + `npm run tokens:build` produces updated outputs on both platforms verifiably.

---

## Phase 6: User Story 4 — Administration Platform Visual Consistency (Priority: P4)

**Goal**: Every implemented admin module — not just the entry screen — uses the Teal theme consistently. No module has its own hardcoded colors.

**Independent Test**: Navigate to dashboard, users, KYC, wallets, transactions, agents, audit logs, reports, and settings. Verify sidebar color, table row height/border, badge color, and button color are consistent across all modules using only CSS variable-backed classes.

- [X] T046 [US4] Audit `wallet-ecosystem/apps/admin/app/(admin)/dashboard/` page and layout files: search for any inline `style={{ color: '#...' }}` or `className="text-[#...]"` or `bg-[#...]` hardcoded Tailwind arbitrary values; replace with semantic CSS-variable-backed token classes
- [X] T047 [P] [US4] Audit `wallet-ecosystem/apps/admin/app/(admin)/admin-portal/users/` pages: same hardcoded-color search and fix as T046
- [X] T048 [P] [US4] Audit `wallet-ecosystem/apps/admin/app/(admin)/configuration/` pages (approvals, domain config): same hardcoded-color search and fix
- [X] T049 [P] [US4] Audit `wallet-ecosystem/apps/admin/app/(admin)/reports/` pages: same hardcoded-color search and fix
- [X] T050 [P] [US4] Audit `wallet-ecosystem/apps/admin/app/(admin)/qa/runs/` and any remaining admin module pages: same hardcoded-color search and fix
- [X] T051 [US4] Verify `wallet-ecosystem/apps/admin/components/ui/Navigation.tsx` (or equivalent sidebar component): confirm the active navigation item uses `var(--ok-nav-active)` or a Tailwind class that maps to it, and the inactive items use `var(--ok-muted)` or equivalent token-backed class
- [X] T052 [US4] Write component snapshot tests for all 15 files in `wallet-ecosystem/apps/admin/components/ui/`: create `wallet-ecosystem/apps/admin/__tests__/components/ui/` directory and one test file per component using `@testing-library/react` with `toMatchSnapshot()`; run once to capture baseline snapshots

**Checkpoint**: Every admin module verified to use only CSS-variable-backed colors. Snapshot baselines captured for all 15 UI components.

---

## Phase 7: User Story 5 — Consumer App Visual Polish (Priority: P5)

**Goal**: Every implemented consumer screen uses the Consumer Green theme from tokens. No screen has hardcoded color values. The app produces no `flutter analyze` warnings.

**Independent Test**: Navigate from onboarding through dashboard, wallet, transaction history, and settings. Run `grep -rn "Color(0x" apps/customer/lib/ --include="*.dart"` — only `design_tokens.dart` (which re-exports from the shared package) should contain `Color(0x...`; all module/screen/widget files must be clean.

- [X] T053 [US5] Fix `wallet-ecosystem/apps/customer/lib/modules/device/presentation/widgets/device_status_badge.dart`: replace three hardcoded `Color(0xFF22C55E)`, `Color(0xFFF59E0B)`, `Color(0xFFEF4444)` with `Theme.of(context).extension<OkThemeExtension>()!.successBg` / `warningBg` / `errorBg` references per FR-005 and FR-029
- [X] T054 [P] [US5] Search `wallet-ecosystem/apps/customer/lib/modules/` for any remaining hardcoded `Color(0x` patterns: `grep -rn "Color(0x" lib/modules/ --include="*.dart"` — fix any found occurrences to use `OkThemeExtension` or `Theme.of(context).colorScheme.*` references
- [X] T055 [P] [US5] Search `wallet-ecosystem/apps/customer/lib/shared/` for any remaining hardcoded `Color(0x` patterns and fix
- [X] T056 [US5] Write `wallet-ecosystem/apps/customer/test/golden/login_screen_test.dart`: widget test that pumps the login screen wrapped in the consumer `ThemeData`, captures a golden file at `test/golden/goldens/login_screen.png`
- [X] T057 [P] [US5] Write `wallet-ecosystem/apps/customer/test/golden/dashboard_screen_test.dart`: widget test that pumps the dashboard screen wrapped in consumer `ThemeData`, captures golden at `test/golden/goldens/dashboard_screen.png`; mock any Riverpod providers needed to render the screen
- [X] T058 [P] [US5] Write `wallet-ecosystem/apps/customer/test/golden/wallet_screen_test.dart`: same pattern for the wallet/balance screen
- [ ] T059 [US5] Run `flutter test --update-goldens test/golden/` in `wallet-ecosystem/apps/customer/` to capture baseline golden files; run `flutter test test/golden/` a second time to confirm they pass without `--update-goldens`

**Checkpoint**: Consumer app has zero hardcoded color values outside of `design_tokens.dart`. Golden test baselines captured and passing.

---

## Phase 8: User Story 6 — Agent App Visual Polish (Priority: P6)

**Goal**: Every implemented agent screen uses the Agent Blue theme. No screen has hardcoded colors. `flutter analyze` returns 0 issues.

**Independent Test**: Navigate from login through dashboard, float overview, customer lookup, and settings. Run `grep -rn "Color(0x" apps/agent/lib/modules/ --include="*.dart"` — must return zero results.

- [X] T060 [P] [US6] Search `wallet-ecosystem/apps/agent/lib/modules/` for hardcoded `Color(0x` patterns: `grep -rn "Color(0x" lib/modules/ --include="*.dart"` — fix any found occurrences to use `OkThemeExtension` or `Theme.of(context).colorScheme.*`
- [X] T061 [P] [US6] Search `wallet-ecosystem/apps/agent/lib/shared/` for hardcoded `Color(0x` patterns and fix
- [X] T062 [US6] Write `wallet-ecosystem/apps/agent/test/golden/login_screen_test.dart`: widget test pumping the agent login screen in agent `ThemeData`, captures golden at `test/golden/goldens/login_screen.png`
- [X] T063 [P] [US6] Write `wallet-ecosystem/apps/agent/test/golden/dashboard_screen_test.dart`: agent dashboard golden test
- [X] T064 [P] [US6] Write `wallet-ecosystem/apps/agent/test/golden/agent_operations_screen_test.dart`: one of the core agent operation screens (float or customer lookup) golden test
- [ ] T065 [US6] Run `flutter test --update-goldens test/golden/` in `wallet-ecosystem/apps/agent/` to capture baselines; run `flutter test test/golden/` to confirm pass

**Checkpoint**: Agent app has zero hardcoded color values in module/shared files. Golden baselines captured and passing.

---

## Phase 9: Polish & Cross-Cutting Validation

**Purpose**: Final regression pass and quickstart scenario verification across all applications.

- [X] T066 Run `flutter analyze` in both `wallet-ecosystem/apps/customer/` and `wallet-ecosystem/apps/agent/` — both must produce `No issues found!` (final clean check after all phases)
- [ ] T067 [P] Run `flutter test` in `wallet-ecosystem/apps/customer/` (including golden tests) — all must pass with zero failures
- [ ] T068 [P] Run `flutter test` in `wallet-ecosystem/apps/agent/` (including golden tests) — all must pass with zero failures
- [X] T069 Run `npm test` in `wallet-ecosystem/apps/admin/` — all existing tests plus new snapshot tests must pass
- [X] T070 Run `npm run tokens:build && npm run tokens:validate` in `wallet-ecosystem/packages/design-system/` — must produce exit code 0 with no errors or unresolved references (final clean build)
- [ ] T071 Execute quickstart.md SC-01 through SC-07 validation scenarios (token build, admin Teal display, hardcoded-color search, propagation test, consumer Green display, agent Blue display, flutter analyze)
- [ ] T072 Execute quickstart.md SC-08 through SC-12 validation scenarios (contrast check, status indicator non-color cues, golden tests, regression tests, reduced-motion)
- [X] T073 Review `specs/035-unified-design-system/accessibility-report.md`: confirm all contrast adjustments per FR-027 are documented with rationale, the base brand colors are preserved in `color.json`, and only the foreground overrides differ

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — read-only reconnaissance
- **Phase 2 (Foundational)**: Depends on Phase 1 understanding; **BLOCKS all user story phases**
- **Phase 3 (US1)**: Requires Phase 2 complete (correct token values in build outputs)
- **Phase 4 (US2)**: Requires Phase 3 complete (apps using correct palettes before measuring contrast)
- **Phase 5 (US3)**: Requires Phase 2 complete; can run in parallel with Phase 4
- **Phase 6 (US4)**: Requires Phase 3 complete; can run in parallel with Phases 4–5
- **Phase 7 (US5)**: Requires Phase 3 complete; can run in parallel with Phases 4–6
- **Phase 8 (US6)**: Requires Phase 3 complete; can run in parallel with Phases 4–7
- **Phase 9 (Polish)**: Requires all prior phases complete

### User Story Dependencies

- **US1 (P1)**: Depends on Foundational (Phase 2) only — MVP deliverable
- **US2 (P2)**: Depends on US1 (need correct palettes before measuring contrast)
- **US3 (P3)**: Depends on Foundational (Phase 2) only — can run alongside US2
- **US4 (P4)**: Depends on US1 admin tasks (T018–T020) only
- **US5 (P5)**: Depends on US1 consumer tasks (T021–T024) only
- **US6 (P6)**: Depends on US1 agent tasks (T025–T028) only

### Parallel Opportunities

- T003–T006 (Phase 1 reads): all parallel
- T007–T013 (color.json palette additions): sequential (same file) but T013 is independent
- T025–T028 (agent ThemeData work): parallel with T021–T024 (consumer ThemeData work)
- T029/T030, T031/T032, T033/T034: each pair parallel (different apps)
- T046–T050 (admin module audits): all parallel (different directories)
- T054–T055 (consumer module/shared searches): parallel
- T056–T058 (consumer golden tests): parallel with each other
- T060–T061 (agent module/shared searches): parallel
- T062–T064 (agent golden tests): parallel with each other
- T067/T068 (flutter test): parallel

---

## Parallel Example: Phase 3 (US1)

```
# Consumer and agent ThemeData work can run in parallel:
Task T021: Add pubspec.yaml dependency — customer
Task T025: Add pubspec.yaml dependency — agent   [PARALLEL with T021]

Task T022: Rewrite design_tokens.dart — customer
Task T026: Rewrite design_tokens.dart — agent    [PARALLEL with T022]

Task T023: Create ok_theme_extension.dart — customer
Task T027: Create ok_theme_extension.dart — agent [PARALLEL with T023]

Task T024: Rewrite app_theme.dart — customer
Task T028: Rewrite app_theme.dart — agent         [PARALLEL with T024]

# After both apps are wired:
Task T031: flutter analyze — customer
Task T032: flutter analyze — agent               [PARALLEL with T031]
```

---

## Implementation Strategy

### MVP First (User Story 1 — Phase 3)

1. Complete Phase 1 (Setup) — understand current state
2. Complete Phase 2 (Foundational) — update `color.json`, rebuild, verify
3. Complete Phase 3 (US1) — wire correct palette to each app
4. **STOP and VALIDATE**: Run quickstart SC-01 through SC-06
5. **MVP DELIVERED**: Each application shows its correct brand color family

### Incremental Delivery

- After Phase 3 (US1): Applications themed correctly ← MVP
- After Phase 4 (US2): Accessibility validated ← Production-ready
- After Phase 5 (US3): Token governance verified ← Governance gate
- After Phase 6 (US4): Admin fully consistent ← Admin quality gate
- After Phase 7 (US5): Consumer fully polished ← Customer quality gate
- After Phase 8 (US6): Agent fully polished ← Agent quality gate
- After Phase 9 (Polish): Full regression pass ← Release-ready

### Suggested Parallel Team Strategy

With two developers after Phase 2:
- **Developer A**: Phase 3 Admin tasks (T018–T020) → Phase 6 Admin consistency (T046–T052)
- **Developer B**: Phase 3 Flutter tasks (T021–T034) → Phase 4 Accessibility (T035–T042) → Phase 7+8 Flutter polish (T053–T065)
