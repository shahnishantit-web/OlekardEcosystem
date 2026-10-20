# @olekard/design-system

Centralized design token package for the OleKard Wallet Ecosystem. Provides a single authoritative source for colors, spacing, and typography across all applications.

## Applications & Themes

| App | Theme | Primary | Background |
|-----|-------|---------|------------|
| Customer (Flutter) | Consumer Green | `#16A34A` | `#001B12` |
| Agent (Flutter) | Agent Blue | `#2563EB` | `#001329` |
| Admin (Next.js) | Admin Teal | `#0F766E` | `#001A1D` |

## How to Add a New Token

1. Edit `tokens/color.json` (or the relevant token source file).
2. Run `npm run tokens:build` — this builds all outputs AND validates them.
3. Commit **both** the source (`tokens/*.json`) and generated files (`build/`, `lib/src/tokens.dart`).

> **Important**: Generated files in `build/` and `lib/src/tokens.dart` must be committed so Flutter applications can use them without requiring a Node.js build step.

## Using Tokens in Each Platform

### Web (Next.js / Admin)

```css
/* globals.css */
@import '../../../packages/design-system/build/css/tokens.css';
```

Then use `[data-theme="admin"]` on `<html>` and reference `--ok-primary`, `--ok-background`, etc. via Tailwind or direct CSS.

### Flutter (Customer / Agent)

Add to `pubspec.yaml`:

```yaml
dependencies:
  design_system:
    path: ../../packages/design-system
```

Then import in Dart:

```dart
import 'package:design_system/design_system.dart';

// Use OkTokens:
final Color primary = OkTokens.colorGreenPrimary;  // #16A34A
```

## Token Validation

```sh
npm run tokens:validate
```

Checks that every token listed in `specs/035-unified-design-system/contracts/token-contract.md` is present and non-empty in both `build/css/tokens.css` and `lib/src/tokens.dart`. Exits with code 1 on any missing token.

## Token Governance

- Modify source: `tokens/*.json`
- Build + validate: `npm run tokens:build`
- Commit both source and generated files
- Never edit `build/` files directly — they will be overwritten by the next build (Note: `build/css/tokens.css` is currently hand-maintained; do not run `npm run tokens:build` until migrating to Style Dictionary 4)
