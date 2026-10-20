# Quickstart Validation Guide: Unified Design System

**Feature**: E35 Unified Design System
**Date**: 2026-10-10

---

## Prerequisites

```
wallet-ecosystem/
├── packages/design-system/   # Token source + Style Dictionary
├── apps/admin/               # Next.js admin app
├── apps/customer/            # Flutter consumer app
└── apps/agent/               # Flutter agent app
```

**Required tools**:
- Node.js 20+ (for `npm run tokens:build`)
- Flutter 3.19+ (for Flutter apps)
- Docker (for admin dev server)

---

## SC-01: Token Build Succeeds with Zero Errors

**Validates**: FR-001, FR-002, FR-006, SC-005

```bash
cd wallet-ecosystem/packages/design-system
npm install
npm run tokens:build
```

**Expected outcome**:
- Exit code 0
- `build/css/tokens.css` generated (or updated)
- `build/dart/tokens.dart` generated (or updated)
- No "unresolved reference" warnings in output

---

## SC-02: Admin App Loads with Teal Theme

**Validates**: FR-009, FR-018, FR-019, FR-020, US3, US4, SC-001

```bash
# Start admin dev server
cd wallet-ecosystem/apps/admin
npm install
npm run dev
# Open http://localhost:3000
```

**Expected outcome**:
- Admin sidebar uses `#0F766E` (Teal) as active/highlight color
- Dashboard KPI cards use `#062A2A` surface background
- Primary buttons are teal-colored, not blue or green
- Background is `#001A1D`
- No visible hardcoded hex colors in browser DevTools → Elements panel for any component

---

## SC-03: Admin App Has Zero Hardcoded Colors in Component Files

**Validates**: FR-005, SC-001

```bash
cd wallet-ecosystem/apps/admin
# Search for hardcoded hex colors in component source files
grep -rn "#[0-9A-Fa-f]\{6\}" components/ app/ --include="*.tsx" --include="*.ts" --include="*.css"
```

**Expected outcome**:
- Zero matches in `components/ui/` files
- Zero matches in `app/` page/layout files
- Only allowable occurrences: `styles/globals.css` where CSS variable declarations themselves define primitive values (i.e., `--color-green-primary: #16A34A` is acceptable; `background: #16A34A` directly on a class is not)

---

## SC-04: Semantic Error Token Propagates Everywhere

**Validates**: FR-002, FR-006, SC-006 (single-source propagation)

```bash
# 1. Edit the error color in the token source
# In packages/design-system/tokens/color.json, temporarily change color.status.error to #FF0000

# 2. Rebuild
cd wallet-ecosystem/packages/design-system && npm run tokens:build

# 3. Verify admin propagation
grep -n "ff0000\|FF0000" wallet-ecosystem/apps/admin/styles/globals.css
# Should find the updated variable value

# 4. Verify Dart propagation
grep -n "0xFFFF0000" wallet-ecosystem/packages/design-system/build/dart/tokens.dart
# Should find the updated constant

# 5. Revert the test change
```

**Expected outcome**: Both admin CSS and Flutter Dart outputs reflect the change with a single token-source edit + rebuild.

---

## SC-05: Flutter Consumer App Uses Consumer Green Theme

**Validates**: FR-007, FR-021, FR-022, US5, SC-001

```bash
cd wallet-ecosystem/apps/customer
flutter pub get
flutter run -d web-server --web-port 3001
# Open http://localhost:3001
```

**Expected outcome**:
- Login screen background: `#001B12` (dark green, not blue or teal)
- Primary CTA button: `#16A34A` (Consumer Green)
- No blue (`#2563EB`) or teal (`#0F766E`) visible

**Verify no hardcoded colors**:
```bash
grep -rn "Color(0x" lib/ --include="*.dart" | grep -v "design_tokens.dart" | grep -v "tokens.dart"
```
Expected: Only references in `design_tokens.dart` which re-exports from shared tokens package. Zero hardcoded `Color(0x...)` in module/screen/widget files.

---

## SC-06: Flutter Agent App Uses Agent Blue Theme

**Validates**: FR-008, FR-023, FR-024, US6, SC-001

```bash
cd wallet-ecosystem/apps/agent
flutter pub get
flutter run -d web-server --web-port 3002
# Open http://localhost:3002
```

**Expected outcome**:
- Login screen background: `#001329` (dark blue, not green or teal)
- Primary CTA button: `#2563EB` (Agent Blue)
- Visual distinction from consumer app immediately visible

---

## SC-07: Flutter analyze Passes with No Issues

**Validates**: FR-022, FR-023, FR-024, SC-009

```bash
cd wallet-ecosystem/apps/customer && flutter analyze
cd wallet-ecosystem/apps/agent && flutter analyze
```

**Expected outcome**: Both commands output `No issues found!`

---

## SC-08: WCAG 2.2 AA Contrast — Admin Primary Text

**Validates**: FR-025, FR-026, SC-002, SC-003

Manual spot-check using browser DevTools or https://webaim.org/resources/contrastchecker/:

| Foreground | Background | Ratio | Requirement | Pass? |
|---|---|---|---|---|
| `#FFFFFF` (text) | `#001A1D` (background) | Expected ≥ 7:1 | 4.5:1 AA | ✓ |
| `#FFFFFF` (text) | `#0F766E` (primary button) | Check | 4.5:1 AA | Verify |
| `#FFFFFF` (text) | `#062A2A` (surface) | Expected ≥ 7:1 | 4.5:1 AA | ✓ |
| `#B8C4D0` (muted text) | `#001A1D` (background) | Check | 4.5:1 AA | Verify |

**If `#FFFFFF` on `#0F766E` fails 4.5:1**: Use documented accessible foreground per FR-027 (dark text or `#001A1D`). Check is: ratio = contrast(#FFFFFF, #0F766E). Actual ratio ≈ 3.2:1 (fails AA for normal text). → Use `#001A1D` dark text on teal buttons, or use `#2DD4BF` (primaryLight) as button bg instead.

---

## SC-09: Status Indicators Have Non-Color Cues

**Validates**: FR-029, SC-008

Navigate to the admin KYC module or transaction list. For each status badge (Pending, Approved, Rejected, Failed):

**Expected outcome**:
- Each badge has an icon OR a text label in addition to its color
- Verified by inspecting badge markup — not just a colored dot with no text/icon

---

## SC-10: Flutter Golden Tests Pass

**Validates**: FR-036, SC-004

```bash
cd wallet-ecosystem/apps/customer
flutter test test/golden/ --update-goldens   # First run: capture baselines
flutter test test/golden/                     # Subsequent runs: compare

cd wallet-ecosystem/apps/agent
flutter test test/golden/ --update-goldens
flutter test test/golden/
```

**Expected outcome**: All golden test comparisons pass. Zero pixel-difference failures after theming is applied and baselines are captured.

---

## SC-11: Existing Tests Pass (No Regressions)

**Validates**: FR-032, FR-034, SC-004

```bash
# Admin
cd wallet-ecosystem/apps/admin && npm test

# Customer Flutter
cd wallet-ecosystem/apps/customer && flutter test

# Agent Flutter
cd wallet-ecosystem/apps/agent && flutter test
```

**Expected outcome**: All test suites pass. Zero failures introduced by theming changes.

---

## SC-12: Reduced-Motion Respected

**Validates**: FR-031, SC-010

In browser (admin app):
1. Open OS accessibility settings → enable "Reduce motion"
2. Navigate the admin app
3. Observe: transitions, animations are suppressed or shortened

In Flutter apps:
1. Enable "Remove animations" in Android Developer Options / iOS Accessibility
2. Navigate the app
3. Observe: page transitions and loading animations are suppressed

**Expected outcome**: No animated transitions play when reduced-motion preference is active.
