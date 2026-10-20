# Accessibility Report — E35 Unified Design System

**Standard**: WCAG 2.2 AA
**Date**: 2026-10-10
**Method**: WCAG relative-luminance formula per W3C specification

---

## WCAG 2.2 AA Thresholds

- Normal text (< 18pt / < 14pt bold): **4.5:1** minimum
- Large text (≥ 18pt or ≥ 14pt bold): **3:1** minimum
- UI component boundaries / graphical objects: **3:1** minimum

---

## 12-Pair Contrast Matrix

### Text Pairs (4.5:1 minimum)

| # | Foreground | Background | Context | Ratio | Result |
|---|-----------|------------|---------|-------|--------|
| 1 | #FFFFFF | #001B12 | Consumer app — text on page background | 18.02:1 | ✅ PASS |
| 2 | #FFFFFF | #062A1A | Consumer app — text on card surface | 15.51:1 | ✅ PASS |
| 3 | #FFFFFF | #001329 | Agent app — text on page background | 18.66:1 | ✅ PASS |
| 4 | #FFFFFF | #061B3A | Agent app — text on card surface | 17.13:1 | ✅ PASS |
| 5 | #FFFFFF | #001A1D | Admin app — text on page background | 18.02:1 | ✅ PASS |
| 6 | #FFFFFF | #062A2A | Admin app — text on card surface | 15.30:1 | ✅ PASS |
| 7 | **#FFFFFF** | **#16A34A** | **Consumer — white text on primary Green button** | **3.30:1** | **❌ FAIL** |
| 8 | #FFFFFF | #2563EB | Agent — white text on primary Blue button | 5.17:1 | ✅ PASS |

### Interactive Control Boundaries (3:1 minimum)

| # | Foreground | Background | Context | Ratio | Result |
|---|-----------|------------|---------|-------|--------|
| 9 | #FFFFFF | #0F766E | Admin — button border on teal primary | 5.47:1 | ✅ PASS |
| 10 | #FFFFFF | #062A2A | Admin — input field border on surface | 15.30:1 | ✅ PASS |
| 11 | #FFFFFF | #0F766E | Admin — white text on teal primary button | 5.47:1 | ✅ PASS |
| 12 | #FFFFFF | #294052 | Admin — focus ring on dark background | 10.77:1 | ✅ PASS |

---

## Findings

### F1 — FAIL: White text on Consumer Green Primary (Pair 7)

**Pair**: `#FFFFFF` (text) on `#16A34A` (Consumer Green primary button background)
**Ratio**: 3.30:1
**Requirement**: 4.5:1 for normal text (WCAG 2.2 AA)
**Impact**: Button labels at 16px/medium weight rendered on Consumer Green primary buttons fail normal-text contrast.

**Remediation** (per T036 / FR-027):
- Add accessible foreground override: `--ok-foreground-on-primary: #001B12`
- `#001B12` on `#16A34A` = **5.45:1** (computed: L₁=0.268, L₂=0.0083, ratio=(0.268+0.05)/(0.0083+0.05)) ✅ PASS
- The base brand color `#16A34A` is **preserved unchanged** in `color.json`
- Only the **foreground text color** on primary surfaces is overridden

**Implementation**:
- Added `--ok-foreground-on-primary: #001B12` to `[data-theme="consumer"]` in `tokens.css`
- Added `colorForegroundOnPrimary: Color(0xFF001B12)` to `OkTokens` in `tokens.dart`
- Flutter: `ElevatedButton.styleFrom(foregroundColor: AppColors.background)` ensures dark text

---

## Summary

| Metric | Value |
|--------|-------|
| Total pairs checked | 12 |
| Passing | 11 |
| Failing | 1 |
| Failure rate | 8.3% |
| Critical failures (blocks usage) | 1 |

**Overall status**: REMEDIATED — all base brand colors preserved; one accessible foreground override applied.

---

## Approved Foreground Overrides

| Token | CSS Variable | Dart Constant | Value | Use |
|-------|-------------|---------------|-------|-----|
| Consumer foreground on primary | `--ok-foreground-on-primary` | `OkTokens.colorForegroundOnPrimary` | `#001B12` | Text/icons on Consumer Green primary (#16A34A) backgrounds |

---

## Notes

- All text on dark app backgrounds (#001B12, #001329, #001A1D, #062A1A, #061B3A, #062A2A) achieves ≥15:1 ratio — far exceeds WCAG AA.
- Agent Blue (#2563EB) and Admin Teal (#0F766E) both pass 4.5:1 with white text.
- Consumer Green (#16A34A) requires dark foreground for button labels. This is a standard pattern for medium-lightness greens.
- Non-color status cues (labels + color) verified in T037–T038.
- Reduced-motion support verified (T039 — already present in globals.css).
- Focus rings verified at 10.77:1 contrast ratio (T040).
