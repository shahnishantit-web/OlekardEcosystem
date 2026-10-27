# Research: P22 — Shared Flutter Checkout and Recovery

**Date**: 2026-10-26 | **Status**: Complete — no NEEDS CLARIFICATION items remain

---

## RES-001: Package Structure Convention

**Decision**: Follow `packages/design-system` and `packages/shared-utils` conventions.

**Rationale**: Two packages already exist at `wallet-ecosystem/packages/`. Both use `pubspec.yaml` with `publish_to: none`, Dart `sdk: '>=3.0.0 <4.0.0'`, and are referenced by consuming apps via `path: ../../packages/<name>`. The new `packages/shared-payment/` follows this exact pattern.

**Alternatives considered**: Publishing to pub.dev private feed — rejected; no current infrastructure for private pub, and monorepo path references are simpler.

---

## RES-002: Implementation Divergence Between Customer and Agent

**Finding**: The two existing checkout implementations are ~95% identical. Key divergences:

| Aspect | Customer | Agent |
|--------|----------|-------|
| Notifier base | `AsyncNotifier.autoDispose.family` with record param | `AutoDisposeAsyncNotifier` with `init()` |
| Idempotency keys | `_intentKey` + `_attemptKey` as instance fields, reused on retry | New `Uuid().v4()` per invocation (bug: creates duplicate on retry) |
| Poll timeout | Stays in `PollingStatus(processing)` | Transitions to `PaymentExpired()` (incorrect per spec) |
| `_pollGeneration` guard | Yes — prevents stale poll updates | No |
| `_disposed` guard | Yes | No |
| Step-up view | Full `PinPadWidget` + real `verifyStepUp()` API | Stub — returns mock token |
| API URL prefix | `/api/v1/payment/...` | `/v1/payment/...` |
| Options query params | `currency=`, `amount=` | `currency_code=`, `estimated_amount_minor=` |

**Decision**: Use the customer implementation as the canonical base (it correctly implements idempotency key reuse, poll generation guards, and timeout behaviour). Resolve URL prefix divergence in favour of `/api/v1/payment/...` (consistent with all other API clients in the codebase).

**Agent notifier migration**: Change agent to use `AsyncNotifier.autoDispose.family` with the same `PaymentCheckoutRequest` record. The `init()` pattern is incompatible with Riverpod `family` semantics in Riverpod 3.x.

---

## RES-003: Typed NextAction

**Decision**: Define a `sealed class NextAction` with four subtypes:

```dart
sealed class NextAction {}
class WalletPinAction extends NextAction {}
class RedirectAction extends NextAction {
  final String redirectUrl;
  final List<String> allowedReturnSchemes; // from server
}
class SdkLaunchAction extends NextAction {
  final String sdkId;
  final Map<String, String> sdkParams;
}
class AppSwitchAction extends NextAction {
  final String deepLinkUrl;
  final String? fallbackUrl;
}
class ProviderUnavailableAction extends NextAction {
  final String reason;
}
```

**Rationale**: Current code uses raw string comparison `nextActionType == 'redirect'`. FR-003/US3 requires typed next actions. A sealed class enables exhaustive switch expressions and makes unhandled action types a compile error.

**Allowlist validation**: The `RedirectAction.allowedReturnSchemes` list is populated from the server response alongside the redirect URL. Before treating any app-return event as "back from redirect", the checkout checks the return scheme against this list. Even if scheme matches, polling is still required — matching scheme is never treated as payment proof.

---

## RES-004: Three Recovery States

**Decision**: Replace the single `PollingStatus` with three distinct states for recovery scenarios:

```dart
class RecoveringUnknown extends PaymentCheckoutState {
  final String paymentIntentId;
  final String supportReference;  // for display
}
class RecoveringPending extends PaymentCheckoutState {
  final String paymentIntentId;
}
class RecoveringFulfilling extends PaymentCheckoutState {
  final String paymentIntentId;
}
```

**State mapping from `PaymentIntentStatus`**:
- `pending` → `RecoveringPending` (payment submitted, awaiting provider processing)
- `processing` → `RecoveringPending` (processing in progress)
- `awaitingAction` → `RecoveringUnknown` (provider needs external action — ambiguous)
- `requiresConfirmation` → `RecoveringUnknown` (confirmation pending)
- `fulfilling` → `RecoveringFulfilling` (payment captured; wallet credit pending)
- Any unrecognised non-terminal status → `RecoveringUnknown` (safe default)

**`PaymentIntentStatus` enum**: Add `fulfilling` value. The `fromJson` already handles unknown → `processing` as fallback for graceful degradation if the server doesn't yet emit this status; `fulfilling` is added explicitly for when P06 begins returning it.

**Retain `PollingStatus`** for in-session post-redirect polling (not recovery). Recovery states are only shown when the notifier starts with a persisted intent ID from a previous session (cold start or process kill).

---

## RES-005: StepUpService Abstraction

**Decision**: Define `abstract interface class StepUpService` with a single method:

```dart
abstract interface class StepUpService {
  Future<String> acquireToken({required String scope});
}
```

**Rationale**: Customer app has `PinPadWidget` + `/v1/auth/step-up/` API; agent app has a stub. The shared package cannot embed either implementation. The `_StepUpView` in the shared package reads `stepUpServiceProvider` — each app overrides this provider with its implementation.

**Provider**: `final stepUpServiceProvider = Provider<StepUpService>((ref) => throw UnimplementedError('Override stepUpServiceProvider in your ProviderScope'));`

Consuming apps override in their `ProviderScope`:
```dart
ProviderScope(
  overrides: [
    stepUpServiceProvider.overrideWithValue(CustomerStepUpService(ref)),
  ],
  child: ...,
)
```

---

## RES-006: Recovery Record Persistence

**Decision**: Keep `SharedPreferences` with key `payment_pending_intent_id`. Value is the intent UUID string.

**Write**: Before opening redirect URL (after `AwaitingRedirect` state entered).  
**Clear**: When terminal status received; when user explicitly cancels.  
**Read**: In `PaymentCheckoutSheet.initState()` — if key exists, skip options loading and go directly to recovery polling.

**Alternatives considered**: `flutter_secure_storage` — rejected; the intent ID is not a secret (it's a UUID used to query status). Secure storage is heavier and requires platform-specific setup not needed here.

---

## RES-007: Riverpod Version Alignment

**Finding**: Customer app uses `flutter_riverpod ^3.1.0`; agent uses `flutter_riverpod ^2.5.1`. The shared package will require `^3.1.0`. The agent app's `pubspec.yaml` must be updated to `^3.1.0` as part of this migration.

**Decision**: Upgrade agent to Riverpod 3.x. Breaking changes between 2.x and 3.x are minimal for the patterns used (the `AutoDisposeAsyncNotifier` base class still exists; the main change is `state.value` → `state.valueOrNull` which the agent already uses).

---

## RES-008: API URL Prefix Resolution

**Decision**: Shared package uses `/api/v1/payment/...` (customer convention). The divergence comes from the agent app's `ApiClient` base URL or prefix configuration — both resolve to the same backend endpoint. The shared package uses the full explicit path to avoid ambiguity.

**Query parameter names for getOptions**: Use `currency_code` and `estimated_amount_minor` (agent convention; these are the correct snake_case names matching the backend OpenAPI spec). Customer used shortened `currency` and `amount` which appears to be a legacy typo.

---

## RES-009: Provider Replacement Block (FR-011)

**Decision**: Track `_attemptSubmitted` boolean in `PaymentNotifier`. Once `initiateAttempt` returns successfully, set to `true`. Any subsequent call to `confirmPayment` with a different provider code returns early without creating a new attempt. The idempotency key for the attempt is fixed at first submission; retries reuse the same key.

---

## Summary of Resolved Unknowns

| Unknown | Resolution |
|---------|-----------|
| Package location | `packages/shared-payment/` following existing convention |
| Riverpod pattern | `AsyncNotifier.autoDispose.family` with record param (customer version) |
| NextAction typing | Sealed class with 5 subtypes (4 action types + ProviderUnavailable) |
| Recovery states | 3 distinct states: RecoveringUnknown / RecoveringPending / RecoveringFulfilling |
| Step-up abstraction | `StepUpService` interface; app provides via provider override |
| URL prefix | `/api/v1/payment/...` (customer convention) |
| Query params | `currency_code` + `estimated_amount_minor` (agent convention — correct) |
| Riverpod version | Upgrade agent to `^3.1.0` |
| Provider replacement | `_attemptSubmitted` guard in notifier |
| Allowlist validation | `RedirectAction.allowedReturnSchemes` from server; checked on app resume |
