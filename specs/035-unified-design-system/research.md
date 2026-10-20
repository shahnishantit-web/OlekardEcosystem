# Research: Unified Design System & Multi-Application Theming

**Feature**: E35 Unified Design System
**Date**: 2026-10-10

---

## Decision 1 — Token Pipeline Tool

**Decision**: Use the existing Style Dictionary setup at `packages/design-system/`.

**Rationale**: Style Dictionary is already installed and functional. It currently generates `build/css/tokens.css` (consumed by admin's `globals.css`) and `build/dart/tokens.dart` (generated but not yet imported by Flutter apps). No new tooling is needed. Build command: `npm run tokens:build` run from `packages/design-system/`.

**Alternatives considered**: Theo (Tokens Studio), custom Node script, manual maintenance. All rejected: existing tooling works, adding a new tool would require migration; manual maintenance violates FR-006 (single source).

**Source files**: `packages/design-system/tokens/color.json`, `spacing.json`, `typography.json`, `elevation.json`, `radius.json`, `motion.json`, `breakpoints.json`.

---

## Decision 2 — Token Source Update (color.json)

**Decision**: Update `packages/design-system/tokens/color.json` to add the six approved application palettes (Consumer Green, Agent Blue, Admin Teal, Merchant Orange, Connect Purple, ODSA Gold) alongside the existing master brand and primitive palettes.

**Current state**: `color.json` contains a generic Material-influenced palette. It does NOT contain the approved OleKard application-specific colors. The CSS output references `--ok-primary`, `--ok-background`, etc. but these are mapped to placeholder values, not the approved brand palette.

**Rationale**: All approved HEX values from FR-007 through FR-012 must be encoded in `color.json` as the single authoritative source. All downstream CSS and Dart outputs are regenerated from this source — no values are hand-edited in the generated files.

---

## Decision 3 — Multi-Theme Web Architecture

**Decision**: The admin app uses a single Teal theme at `:root`. Application-specific platform overrides (`--ok-primary`, `--ok-background`, `--ok-surface`, `--ok-accent`, `--ok-primary-light`, `--ok-primary-dark`) are set to Teal values in the generated `tokens.css`. Future applications (Merchant, Connect) will consume their own generated theme files when those apps exist.

**Rationale**: Only the admin application currently exists on the web stack. A single `:root` block is the simplest correct approach. The Style Dictionary config is extended to also generate per-application theme CSS files (e.g., `build/css/themes/admin.css`, `build/css/themes/consumer.css`) for future use. The admin `globals.css` imports the admin theme file specifically.

**Impact on tailwind.config.ts**: No changes needed to the Tailwind config. CSS variables already drive all color values — updating the variable values in `tokens.css` automatically updates all Tailwind classes that reference them.

---

## Decision 4 — Flutter Integration

**Decision**: Add `packages/design-system` as a local path dependency in each Flutter app's `pubspec.yaml`. Rewrite each app's `lib/core/theme/design_tokens.dart` to import and re-export constants from the shared package's generated `tokens.dart`. Rewrite `lib/core/theme/app_theme.dart` to build ThemeData from those shared constants, applying the correct application-specific palette per app.

**Current state**:
- `packages/design-system/build/dart/tokens.dart` is generated but unused by Flutter apps.
- Customer app `lib/core/theme/design_tokens.dart`: Uses Google Blue palette (`Color(0xFF1A73E8)` primary) — wrong.
- Agent app `lib/core/theme/design_tokens.dart`: Uses Material Blue-900 (`Color(0xFF0D47A1)` primary) — wrong.
- Neither app's `pubspec.yaml` references the design system package.

**Implementation path**:
1. Add `design_system: { path: ../../packages/design-system }` to each app's `pubspec.yaml`.
2. Run `flutter pub get` in each app.
3. Replace `AppColors` in each app's `design_tokens.dart` with references to generated token constants.
4. Rebuild `ThemeData` in `app_theme.dart` using Consumer Green (customer) or Agent Blue (agent) palette values from the shared tokens.

---

## Decision 5 — ThemeExtension for Flutter

**Decision**: Introduce `OkThemeExtension` as a `ThemeExtension<OkThemeExtension>` in each Flutter app. This extension carries application-specific semantic color slots not covered by Material ThemeData: `navActiveColor`, `surfaceElevated`, `successColor`, `warningColor`, `infoColor`, `serviceWallet`, `servicePayments`, etc.

**Rationale**: Material ThemeData has `colorScheme.primary`, `colorScheme.surface`, etc. but does not have slots for OleKard-specific semantics (float status colors, service icon colors, agent-specific KPI card colors). ThemeExtension is the idiomatic Flutter 3.x approach for this.

**No new shared package**: Each app defines its own `OkThemeExtension` populated with values from the shared token constants. This satisfies FR-024 (theme differences via configuration, not new packages).

---

## Decision 6 — Hardcoded Color Inventory

**Current hardcoded values found** (must be eliminated per FR-005 / SC-001):

| File | Platform | Hardcoded values |
|------|----------|-----------------|
| `apps/customer/lib/core/theme/design_tokens.dart` | Flutter | All 9 AppColors constants — Google/Material palette (wrong app palette) |
| `apps/customer/lib/modules/device/presentation/widgets/device_status_badge.dart` | Flutter | `Color(0xFF22C55E)`, `Color(0xFFF59E0B)`, `Color(0xFFEF4444)` — semantic status colors |
| `apps/agent/lib/core/theme/design_tokens.dart` | Flutter | All AppColors constants — Material Blue-900 palette (wrong app palette) |
| `apps/admin/styles/globals.css` | Web | `.status-pill`: `#e5f4eb`, `#a9d7b8`, `#17633a` (3 hardcoded hex values) |

**No hardcoded colors found** in admin component files (all use CSS variable-backed Tailwind classes). Agent module screens also use `AppColors.*` references correctly — only the token source file has wrong values.

---

## Decision 7 — Visual Regression Testing

**Decision**: Flutter golden tests for primary screens (login, dashboard, wallet/float overview); Next.js component snapshot tests for the 15 admin UI components in `apps/admin/components/ui/`. No external visual regression service.

**Current state**: No golden tests exist in either Flutter app. Admin app has no component snapshot tests identified. Golden test baselines will be captured after theming is confirmed correct — not before (avoids capturing incorrect baselines).

**Flutter golden test approach**: Use `flutter_test` built-in golden file support. Create `test/golden/` directory in each app. Test key screens with `testWidgets(..., (tester) async { ... await expectLater(find.byType(Screen), matchesGoldenFile(...)) })`. Run with `flutter test --update-goldens` once to capture baselines.

**Admin snapshot test approach**: Use Vitest or Jest component tests. `apps/admin/` already has a test setup; extend it with `@testing-library/react` snapshot tests for each of the 15 UI components.

---

## Decision 8 — Accessibility Verification

**Decision**: Run automated contrast validation using the `color-contrast` npm package (or equivalent) as a CI step that reads the generated `tokens.css` and validates all foreground/background pairings meet WCAG 2.2 AA. For Flutter, verify using `flutter_accessibility_test` or manual spot-check against WCAG ratios.

**Known risk**: Some approved brand colors may not achieve 4.5:1 contrast with white text. Per FR-027:
- Admin Teal `#0F766E` on `#001A1D` background — primary on background: needs verification.
- Consumer Green `#16A34A` on `#001B12` background — primary on background: needs verification.
- Agent Blue `#2563EB` on `#001329` background — acceptable (blue on near-black).

These will be computed during implementation. Where an approved color fails contrast, a documented accessible foreground (dark or lighter shade) is used. The base brand color is not replaced.

---

## Decision 9 — Implementation Order

Based on dependency analysis:

1. Update `packages/design-system/tokens/color.json` with all approved palettes → rebuild → verify CSS and Dart outputs.
2. Update admin `globals.css` import to use admin-specific theme output → fix `.status-pill` hardcoded values.
3. Update admin Tailwind config `--ok-*` variable mappings to reference new Teal palette tokens.
4. Wire Flutter apps to design system package → rewrite `design_tokens.dart` + `app_theme.dart` in each app.
5. Introduce `OkThemeExtension` in each Flutter app.
6. Fix remaining hardcoded color in `device_status_badge.dart` (customer app).
7. Run `flutter analyze` on both Flutter apps.
8. Write golden tests (Flutter) and snapshot tests (admin) after theming is confirmed.
9. Run accessibility contrast validation.

**Risk**: Admin app is already functional and tokenized. The main risk is regenerating `tokens.css` with new values breaking existing admin UI. Mitigation: review the full generated output before deploying; use a side-by-side visual check on the admin app after regeneration.
