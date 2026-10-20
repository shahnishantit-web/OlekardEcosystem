# OleKard Design System

Single source of truth for color, typography, spacing, elevation, radius, and motion across all OleKard platforms.

## Architecture

```
Foundation Tokens  (color palettes, raw scales)
       ↓
Semantic Tokens    (--color-brand-primary, --ok-success, etc.)
       ↓
Platform Theme     ([data-theme="admin|consumer|agent|connect|merchant|direct"])
       ↓
Component Tokens   (--ok-nav-active, --ok-success-bg/fg, etc.)
       ↓
UI Classes         (Tailwind CSS utility classes backed by CSS custom properties)
```

All token values live in `wallet-ecosystem/packages/design-system/`:
- Source: `tokens/*.json` (Style Dictionary DTCG format)
- Web build: `build/css/tokens.css` (CSS custom properties)
- Flutter build: `build/dart/tokens.dart` (`AppTokens` abstract class)

## Brand Foundation

| Token | Value | Usage |
|-------|-------|-------|
| `--ok-brand-navy` | `#001426` | OleKard primary brand, wordmark |
| `--ok-brand-gold` | `#F5B82E` | OleKard secondary brand, accent |

These are static — never overridden by platform theme.

## Platform Themes

Apply via `data-theme` attribute on `<html>`. Each theme overrides the semantic aliases and `--ok-*` platform tokens.

| Platform | Theme key | Primary | Background style |
|----------|-----------|---------|-----------------|
| Admin | `admin` | Teal `#0F766E` | Dark professional `#001A1D` |
| Consumer | `consumer` | Green `#16A34A` | Light `#FFFFFF` |
| Agent | `agent` | Blue `#2563EB` | Dark navy `#001B2E` |
| Connect | `connect` | Violet `#7C3AED` | Light `#FFFFFF` |
| Merchant | `merchant` | Orange `#F97316` | Light `#FFFFFF` |
| Direct/ODSA | `direct` | Gold `#D4A017` | Light `#FFFFFF` |

### Theme switching

```html
<!-- Admin web app -->
<html data-theme="admin">

<!-- Consumer web surface -->
<html data-theme="consumer">
```

Flutter apps use `AppTokens` constants from `tokens.dart` directly — CSS themes do not apply to Flutter.

## OK Platform Tokens

These are the canonical tokens for components to consume. Each `[data-theme]` block overrides them.

| Token | Default | Description |
|-------|---------|-------------|
| `--ok-primary` | `#0F766E` | Platform accent (button, link, active state) |
| `--ok-primary-dark` | `#0D5A55` | Hover/pressed state |
| `--ok-primary-light` | `#14B8A6` | Focus ring, highlights |
| `--ok-background` | `#FFFFFF` | Page background |
| `--ok-surface` | `#F9FAFB` | Card / panel background |
| `--ok-accent` | `#F5B82E` | Brand gold accent |
| `--ok-nav-active` | `rgba(...)` | Active nav item background |

## Semantic Status Tokens

| Token | Value | Usage |
|-------|-------|-------|
| `--ok-success` | `#22C55E` | Success indicator |
| `--ok-warning` | `#F59E0B` | Warning indicator |
| `--ok-error` | `#EF4444` | Error/destructive |
| `--ok-info` | `#38BDF8` | Informational |

### Badge tokens

Badge background/foreground pairs adapt automatically to dark platform themes:

| Token | Light value | Dark (admin/agent) |
|-------|-------------|-------------------|
| `--ok-success-bg` | `#F0FDF4` | `rgba(34,197,94,0.15)` |
| `--ok-success-fg` | `#15803D` | `#86EFAC` |
| `--ok-warning-bg` | `#FFFBEB` | `rgba(245,158,11,0.15)` |
| `--ok-warning-fg` | `#92400E` | `#FCD34D` |
| `--ok-error-bg` | `#FEF2F2` | `rgba(239,68,68,0.15)` |
| `--ok-error-fg` | `#991B1B` | `#FCA5A5` |
| `--ok-info-bg` | `#F0F9FF` | `rgba(56,189,248,0.15)` |
| `--ok-info-fg` | `#1E40AF` | `#7DD3FC` |

## Service Taxonomy Colors

| Service | Token | Value |
|---------|-------|-------|
| Transfers | `--ok-service-transfers` | `#4F46E5` |
| Payments | `--ok-service-payments` | `#059669` |
| Loans | `--ok-service-loans` | `#D97706` |
| Insurance | `--ok-service-insurance` | `#7C3AED` |
| Savings | `--ok-service-savings` | `#0369A1` |
| Compliance/KYC | `--ok-service-compliance` | `#6B7280` |

## Integration and Role Colors

| Category | Token | Value |
|----------|-------|-------|
| API integration | `--ok-integration-api` | `#0891B2` |
| Webhook | `--ok-integration-webhook` | `#7C3AED` |
| Partner | `--ok-integration-partner` | `#F59E0B` |
| Admin role | `--ok-role-admin` | `#0F766E` |
| Agent role | `--ok-role-agent` | `#2563EB` |
| Customer role | `--ok-role-customer` | `#16A34A` |
| Merchant role | `--ok-role-merchant` | `#F97316` |

## Tailwind CSS Integration

The admin app's `tailwind.config.ts` maps all CSS custom properties to utility classes. No hard-coded HEX values in components.

```tsx
// Correct — CSS custom property backed
<div className="bg-ok-surface text-text-primary border-neutral-200">

// Correct — semantic badge
<Badge variant="success" label="Active" />   // renders bg-ok-success-bg text-ok-success-fg

// Wrong — do not use raw Tailwind color utilities
<div className="bg-green-50 text-green-700">
```

### Available ok.* Tailwind classes

- `bg-ok-primary`, `text-ok-primary`, `border-ok-primary`
- `bg-ok-surface`, `bg-ok-background`, `bg-ok-accent`
- `bg-ok-nav-active` — active sidebar/nav item background
- `bg-ok-success-bg` / `text-ok-success-fg` (and warning/error/info variants)
- `bg-brand-navy`, `text-brand-navy`, `bg-brand-gold`, `text-brand-gold`

## Flutter Token Usage

```dart
// Use AppTokens constants — never hard-code Color(0xFFXXXXXX) in app code
colorScheme: ColorScheme(
  primary: AppTokens.colorConsumerPrimary,  // Consumer/Green
  primary: AppTokens.colorAgentPrimary,     // Agent/Blue
)

// Dark agent surfaces
surface: AppTokens.colorAgentBackground,
surfaceContainerHighest: AppTokens.colorAgentSurface,
```

## Typography

| Token | Value | Usage |
|-------|-------|-------|
| `--font-size-xs` | `0.75rem` | Labels, captions |
| `--font-size-sm` | `0.875rem` | Secondary text, badges |
| `--font-size-base` | `1rem` | Body copy |
| `--font-size-lg` | `1.125rem` | Subheadings |
| `--font-size-xl` | `1.25rem` | Section headings |
| `--font-size-2xl` | `1.5rem` | Page headings |
| `--font-size-3xl` | `1.875rem` | Hero headings |
| `--font-size-4xl` | `2.25rem` | Display headings |

Font weights: `--font-weight-normal` (400), `--font-weight-medium` (500), `--font-weight-semibold` (600), `--font-weight-bold` (700).

## Spacing (4px grid)

Token names map to Tailwind spacing: `p-4` = `--spacing-4` = `16px`.

## Elevation

| Token | Usage |
|-------|-------|
| `shadow-sm` | Cards, dropdowns |
| `shadow-md` | Popovers, floating elements |
| `shadow-lg` | Dialogs, modals |
| `shadow-xl` | Toasts, overlays |

## Border Radius

| Token | Value | Usage |
|-------|-------|-------|
| `rounded-sm` | `2px` | Tight elements |
| `rounded-md` | `6px` | Inputs, buttons |
| `rounded-lg` | `8px` | Cards, panels |
| `rounded-xl` | `12px` | Large cards |
| `rounded-full` | `9999px` | Badges, avatars |

## Accessibility

- Focus ring: `2px solid var(--color-brand-primary)` via `:focus-visible` in `globals.css`
- Minimum touch target: 44×44px on interactive elements
- Reduced motion: `prefers-reduced-motion: reduce` disables all transitions/animations
- Badge and status pairs meet WCAG AA contrast (4.5:1 minimum) in both light and dark themes
- Dark themes (admin, agent) use `#F1F5F9` text on dark navy backgrounds

## Shared Components

Buttons, Inputs, Selects, Cards, Dialogs, Sheets, Toasts, Tables, Tabs, Navigation, Badges, Wallet Balance, Transaction Item, Status Badge, Confirmation, Error/Empty/Loading states.

All components use CSS custom property-backed Tailwind classes. No component may hard-code HEX values.

## Rebuilding Tokens

After editing `tokens/*.json`, run:

```sh
cd wallet-ecosystem/packages/design-system
npm run tokens:build
```

This regenerates `build/css/tokens.css` and `build/dart/tokens.dart`. The `[data-theme]` platform blocks in `tokens.css` and the platform-specific constants in `tokens.dart` are hand-maintained additions — do not discard them when rebuilding.
