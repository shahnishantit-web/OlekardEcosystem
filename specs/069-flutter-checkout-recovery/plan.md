# Implementation Plan: P22 — Shared Flutter Checkout and Recovery

**Branch**: `069-flutter-checkout-recovery` | **Date**: 2026-10-26 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/069-flutter-checkout-recovery/spec.md`

---

## Summary

Extract the duplicate payment-checkout logic from `apps/customer` and `apps/agent` into a single Flutter package at `packages/shared-payment/`. The package exposes one entry point (`showPaymentCheckout` / `PaymentCheckoutSheet`) backed by a Riverpod `PaymentNotifier`, extends the state machine with typed next actions (wallet_pin, redirect, sdk_launch, app_switch) and three distinct recovery states (unknown/pending/fulfilling), enforces server-side allowlist validation before trusting any redirect return, and persists a `RecoveryRecord` to SharedPreferences so restarts resume at "checking status" not options.

---

## Technical Context

**Language/Version**: Dart 3.3+ / Flutter 3.22+

**Primary Dependencies**:
- `flutter_riverpod ^3.1.0` (customer version — agent is on 2.5.1 and will be upgraded)
- `dio ^5.4.3` (HTTP client)
- `shared_preferences ^2.3.0` (recovery record persistence)
- `uuid ^4.4.0` (idempotency key generation)
- `url_launcher ^6.3.2` (bank redirect launch)
- `design_system` (path: ../../packages/design-system)

**Storage**: `SharedPreferences` — `payment_pending_intent_id` key; cleared on terminal status.

**Testing**: `flutter_test`, `mocktail`; widget tests with `ProviderScope` overrides.

**Target Platform**: iOS 12+ / Android 6.0+ (both customer and agent apps)

**Project Type**: Flutter package library (`publish_to: none`)

**Performance Goals**:
- Options screen visible within 2 seconds on 4G (NFR-001)
- Recovery resume within 1 second of app foreground (NFR-002)

**Constraints**:
- No provider secrets stored on device (NFR-003)
- No amount computed locally; server is authoritative
- Redirect URL params MUST NOT be treated as payment proof; always poll
- Polling timeout is NOT evidence of expiry — stay in PollingStatus

**Scale/Scope**: 2 consuming apps (customer + agent), 1 shared library

---

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|-------|
| P4 — Financial Integrity | PASS | Amounts server-determined; Idempotency-Key per attempt; no local arithmetic |
| P5 — Identity / Authorization | PASS | Step-up token obtained server-side; never stored |
| P6 — Zero Trust | PASS | URL params ignored; polling only; no plaintext credentials on device |
| P7 — Domain Boundaries | PASS | Repository interface; notifier delegates all decisions to server |
| P8 — API Contracts | PASS | `/api/v1/payment/` endpoints; `Idempotency-Key` header on mutations |
| P9 — Event Reliability | N/A | Mobile client only; no event emission |
| P10 — UX / Design System | PASS | Uses design_system tokens; distinct states for all financial outcomes |
| P14 — No Silent Arch Changes | PASS | Flutter retained; new package follows packages/design-system convention |

All gates pass.

---

## Project Structure

### Documentation (this feature)

```text
specs/069-flutter-checkout-recovery/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   ├── public-api.md
│   └── api-endpoints.md
├── quickstart.md        # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code

```text
packages/shared-payment/
├── pubspec.yaml
├── lib/
│   ├── shared_payment.dart                         # barrel export (public API)
│   └── src/
│       ├── domain/
│       │   ├── models/
│       │   │   ├── payment_intent.dart
│       │   │   ├── payment_option.dart
│       │   │   ├── payment_status.dart             # PaymentIntentStatus (+ fulfilling value)
│       │   │   ├── next_action.dart                # NextAction sealed class (NEW)
│       │   │   ├── checkout_result.dart
│       │   │   └── recovery_record.dart            # NEW
│       │   └── checkout_state.dart                 # sealed PaymentCheckoutState (+ recovery states)
│       ├── application/
│       │   ├── payment_notifier.dart               # merged PaymentNotifier
│       │   └── step_up_service.dart                # abstract StepUpService (NEW)
│       ├── data/
│       │   ├── payment_api_client.dart             # unified /api/v1/payment/ client
│       │   └── payment_repository.dart
│       └── presentation/
│           ├── payment_checkout_sheet.dart
│           ├── payment_status_screen.dart
│           └── widgets/
│               ├── payment_option_tile.dart
│               ├── wallet_balance_tile.dart
│               └── payment_amount_summary.dart
└── test/
    ├── domain/
    │   ├── next_action_test.dart
    │   └── checkout_state_test.dart
    ├── application/
    │   └── payment_notifier_test.dart
    └── presentation/
        └── payment_checkout_sheet_test.dart

# Consuming apps — local payment module replaced by package import:
apps/customer/pubspec.yaml   # add shared_payment dependency
apps/agent/pubspec.yaml      # add shared_payment dependency
```

**Structure Decision**: Flutter package library following `packages/design-system` convention. `src/` keeps implementation private. Barrel export at `lib/shared_payment.dart` exposes: `PaymentCheckoutSheet`, `showPaymentCheckout`, `CheckoutResult`, `PaymentCheckoutMode`, `PaymentCheckoutRequest`, `StepUpService`.

---

## Complexity Tracking

The `StepUpService` interface adds one abstraction justified by FR-014: the two apps have divergent step-up implementations (customer has `PinPadWidget` + real auth API; agent has a stub pending E32 wiring). Embedding app-specific auth in the shared library would violate P14. The interface is minimal (one method) and does not introduce additional layers.
