# Token Contract: Unified Design System

**Feature**: E35 Unified Design System
**Date**: 2026-10-10

This document defines the stable token surface that every application depends on. Any token listed here MUST be defined in `packages/design-system/tokens/` and present in all generated platform outputs.

---

## Primitive Color Tokens (CSS names / Dart constant names)

### Master Brand

| CSS Variable | Dart Constant | Value | Purpose |
|---|---|---|---|
| `--color-brand-navy` | `OkTokens.colorBrandNavy` | `#001426` | Primary brand foundation |
| `--color-brand-midnight` | `OkTokens.colorBrandMidnight` | `#000B18` | Dark background |
| `--color-brand-gold` | `OkTokens.colorBrandGold` | `#F5B82E` | OleKard branding |
| `--color-brand-gold-light` | `OkTokens.colorBrandGoldLight` | `#FFC94A` | Brand highlights |
| `--color-neutral-white` | `OkTokens.colorNeutralWhite` | `#FFFFFF` | Primary text |
| `--color-neutral-secondary` | `OkTokens.colorNeutralSecondary` | `#B8C4D0` | Secondary text |
| `--color-neutral-border` | `OkTokens.colorNeutralBorder` | `#294052` | Borders |

### Shared Semantic Status

| CSS Variable | Dart Constant | Value |
|---|---|---|
| `--color-status-success` | `OkTokens.colorStatusSuccess` | `#22C55E` |
| `--color-status-warning` | `OkTokens.colorStatusWarning` | `#F59E0B` |
| `--color-status-error` | `OkTokens.colorStatusError` | `#EF4444` |
| `--color-status-info` | `OkTokens.colorStatusInfo` | `#38BDF8` |
| `--color-status-success-bg` | `OkTokens.colorStatusSuccessBg` | `#052E16` |
| `--color-status-warning-bg` | `OkTokens.colorStatusWarningBg` | `#1C1305` |
| `--color-status-error-bg` | `OkTokens.colorStatusErrorBg` | `#1A0505` |
| `--color-status-info-bg` | `OkTokens.colorStatusInfoBg` | `#041520` |
| `--color-status-success-border` | `OkTokens.colorStatusSuccessBorder` | `#15803D` |
| `--color-status-warning-border` | `OkTokens.colorStatusWarningBorder` | `#B45309` |
| `--color-status-error-border` | `OkTokens.colorStatusErrorBorder` | `#B91C1C` |
| `--color-status-info-border` | `OkTokens.colorStatusInfoBorder` | `#0369A1` |

### Consumer Green Application Palette

| CSS Variable | Dart Constant | Value |
|---|---|---|
| `--color-green-primary` | `OkTokens.colorGreenPrimary` | `#16A34A` |
| `--color-green-dark` | `OkTokens.colorGreenDark` | `#15803D` |
| `--color-green-light` | `OkTokens.colorGreenLight` | `#4ADE80` |
| `--color-green-surface` | `OkTokens.colorGreenSurface` | `#062A1A` |
| `--color-green-background` | `OkTokens.colorGreenBackground` | `#001B12` |
| `--color-green-accent` | `OkTokens.colorGreenAccent` | `#84CC16` |

### Agent Blue Application Palette

| CSS Variable | Dart Constant | Value |
|---|---|---|
| `--color-blue-primary` | `OkTokens.colorBluePrimary` | `#2563EB` |
| `--color-blue-dark` | `OkTokens.colorBlueDark` | `#1D4ED8` |
| `--color-blue-light` | `OkTokens.colorBlueLight` | `#60A5FA` |
| `--color-blue-surface` | `OkTokens.colorBlueSurface` | `#061B3A` |
| `--color-blue-background` | `OkTokens.colorBlueBackground` | `#001329` |
| `--color-blue-accent` | `OkTokens.colorBlueAccent` | `#38BDF8` |

### Administration Teal Application Palette

| CSS Variable | Dart Constant | Value |
|---|---|---|
| `--color-teal-primary` | `OkTokens.colorTealPrimary` | `#0F766E` |
| `--color-teal-dark` | `OkTokens.colorTealDark` | `#115E59` |
| `--color-teal-light` | `OkTokens.colorTealLight` | `#2DD4BF` |
| `--color-teal-surface` | `OkTokens.colorTealSurface` | `#062A2A` |
| `--color-teal-background` | `OkTokens.colorTealBackground` | `#001A1D` |
| `--color-teal-accent` | `OkTokens.colorTealAccent` | `#14B8A6` |

### Merchant Orange Application Palette

| CSS Variable | Dart Constant | Value |
|---|---|---|
| `--color-orange-primary` | `OkTokens.colorOrangePrimary` | `#F97316` |
| `--color-orange-dark` | `OkTokens.colorOrangeDark` | `#C2410C` |
| `--color-orange-light` | `OkTokens.colorOrangeLight` | `#FB923C` |
| `--color-orange-surface` | `OkTokens.colorOrangeSurface` | `#351407` |
| `--color-orange-background` | `OkTokens.colorOrangeBackground` | `#1A0A03` |
| `--color-orange-accent` | `OkTokens.colorOrangeAccent` | `#FBBF24` |

### Connect Purple Application Palette

| CSS Variable | Dart Constant | Value |
|---|---|---|
| `--color-purple-primary` | `OkTokens.colorPurplePrimary` | `#7C3AED` |
| `--color-purple-dark` | `OkTokens.colorPurpleDark` | `#5B21B6` |
| `--color-purple-light` | `OkTokens.colorPurpleLight` | `#A78BFA` |
| `--color-purple-surface` | `OkTokens.colorPurpleSurface` | `#1A0B36` |
| `--color-purple-background` | `OkTokens.colorPurpleBackground` | `#090016` |
| `--color-purple-accent` | `OkTokens.colorPurpleAccent` | `#C084FC` |
| `--color-purple-ai-glow` | `OkTokens.colorPurpleAiGlow` | `#E879F9` |

### ODSA Gold Application Palette

| CSS Variable | Dart Constant | Value |
|---|---|---|
| `--color-gold-primary` | `OkTokens.colorGoldPrimary` | `#D4A017` |
| `--color-gold-dark` | `OkTokens.colorGoldDark` | `#8A6508` |
| `--color-gold-light` | `OkTokens.colorGoldLight` | `#F6C453` |
| `--color-gold-surface` | `OkTokens.colorGoldSurface` | `#302000` |
| `--color-gold-background` | `OkTokens.colorGoldBackground` | `#120D00` |
| `--color-gold-accent` | `OkTokens.colorGoldAccent` | `#F5B82E` |

---

## Semantic Application Variables (Web — CSS)

These CSS variables are set per application theme (in `tokens.css` or theme-specific files):

| CSS Variable | Meaning | Admin Value |
|---|---|---|
| `--ok-primary` | App primary color | `var(--color-teal-primary)` |
| `--ok-primary-dark` | Primary pressed state | `var(--color-teal-dark)` |
| `--ok-primary-light` | Primary subtle | `var(--color-teal-light)` |
| `--ok-background` | App background | `var(--color-teal-background)` |
| `--ok-surface` | Card/panel surface | `var(--color-teal-surface)` |
| `--ok-accent` | Secondary highlight | `var(--color-teal-accent)` |
| `--ok-nav-active` | Active nav item | `var(--color-teal-primary)` |
| `--ok-foreground` | Primary text | `var(--color-neutral-white)` |
| `--ok-muted` | Secondary text | `var(--color-neutral-secondary)` |
| `--ok-border` | Borders | `var(--color-neutral-border)` |

---

## Service Icon Colors (Shared — Both Platforms)

| Service | CSS Variable | Dart Constant | Value |
|---|---|---|---|
| Wallet | `--color-service-wallet` | `OkTokens.colorServiceWallet` | `#F5B82E` |
| Payments | `--color-service-payments` | `OkTokens.colorServicePayments` | `#2563EB` |
| Utilities | `--color-service-utilities` | `OkTokens.colorServiceUtilities` | `#22C55E` |
| Messaging | `--color-service-messaging` | `OkTokens.colorServiceMessaging` | `#06B6D4` |
| AI | `--color-service-ai` | `OkTokens.colorServiceAi` | `#8B5CF6` |
| Notifications | `--color-service-notifications` | `OkTokens.colorServiceNotifications` | `#F59E0B` |
| Security | `--color-service-security` | `OkTokens.colorServiceSecurity` | `#10B981` |
| Rewards | `--color-service-rewards` | `OkTokens.colorServiceRewards` | `#EC4899` |
| Support | `--color-service-support` | `OkTokens.colorServiceSupport` | `#14B8A6` |
| Multilanguage | `--color-service-multilanguage` | `OkTokens.colorServiceMultilanguage` | `#3B82F6` |

---

## Spacing Scale (Shared — Both Platforms)

| Token | CSS Variable | Dart Constant | Value |
|---|---|---|---|
| xs | `--spacing-xs` | `OkTokens.spacingXs` | `4px` / `4.0` |
| sm | `--spacing-sm` | `OkTokens.spacingSm` | `8px` / `8.0` |
| md | `--spacing-md` | `OkTokens.spacingMd` | `16px` / `16.0` |
| lg | `--spacing-lg` | `OkTokens.spacingLg` | `24px` / `24.0` |
| xl | `--spacing-xl` | `OkTokens.spacingXl` | `32px` / `32.0` |
| xxl | `--spacing-2xl` | `OkTokens.spacing2xl` | `48px` / `48.0` |

---

## Contract Guarantees

1. Every token listed in this document MUST be present in `build/css/tokens.css` after `npm run tokens:build`.
2. Every token listed in this document MUST be present as a constant in `build/dart/tokens.dart` after `npm run tokens:build`.
3. No value listed in this document may differ from the approved HEX values in `spec.md` FR-007 through FR-012.
4. Token names are stable — renaming a token is a breaking change and requires updating all consumer references.
5. New tokens may be added without breaking consumers; existing tokens may not be removed without a deprecation cycle.
