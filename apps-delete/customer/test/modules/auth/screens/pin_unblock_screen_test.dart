import 'dart:async' show Completer;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/auth/state/auth_state.dart';
import 'package:customer/modules/auth/state/auth_state_notifier.dart';
import 'package:customer/modules/auth/screens/pin/pin_unblock_screen.dart';
import 'package:customer/modules/auth/storage/auth_token_storage.dart';
import 'package:customer/modules/auth/services/auth_api_service.dart';

// ── Stub notifiers ────────────────────────────────────────────────────────────

class _LockedNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthLocked();

  String? lastInitiateMobile;
  String? lastCompleteChallengeId;
  String? lastCompleteOtp;
  void Function(String)? onInitiate;
  void Function()? onComplete;

  @override
  Future<void> initiatePinUnblock(String mobileNumber) async {
    lastInitiateMobile = mobileNumber;
    onInitiate?.call(mobileNumber);
  }

  @override
  Future<void> completePinUnblock(String challengeId, String otp) async {
    lastCompleteChallengeId = challengeId;
    lastCompleteOtp = otp;
    onComplete?.call();
  }
}

class _UnblockPendingNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => AuthPinUnblockPending(
        challengeId: 'ch-unblock-001',
        expiresAt: DateTime.now().add(const Duration(minutes: 10)),
      );

  String? lastCompleteChallengeId;
  String? lastCompleteOtp;

  @override
  Future<void> completePinUnblock(String challengeId, String otp) async {
    lastCompleteChallengeId = challengeId;
    lastCompleteOtp = otp;
  }
}

class _UnblockErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => AuthPinUnblockPending(
        challengeId: 'ch-unblock-001',
        expiresAt: DateTime(2099),
        errorCode: 'IDENTITY_003',
        errorMessage: 'OTP invalid or expired.',
      );
}

class _LoadingNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() => Completer<AuthState>().future;
}

// ── Test helper ───────────────────────────────────────────────────────────────

Widget _wrap(Widget child, {required AuthStateNotifier notifier}) {
  return ProviderScope(
    overrides: [
      authStateNotifierProvider.overrideWith(() => notifier),
      authTokenStorageProvider.overrideWith((_) => throw UnimplementedError()),
      authApiServiceProvider.overrideWith((_) => throw UnimplementedError()),
    ],
    child: MaterialApp(home: child),
  );
}

// ── Tests (T233) ──────────────────────────────────────────────────────────────

void main() {
  group('PinUnblockScreen — widget tests (T233)', () {
    // ── Mobile entry phase ───────────────────────────────────────────────────

    testWidgets('shows mobile entry form when state is AuthLocked',
        (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(_wrap(const PinUnblockScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Recover locked account'), findsOneWidget);
      expect(find.text('Send Unlock Code'), findsOneWidget);
      expect(find.byType(TextField), findsOneWidget);
    });

    testWidgets('calls initiatePinUnblock with entered mobile number',
        (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(_wrap(const PinUnblockScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '+26771234567');
      await tester.tap(find.text('Send Unlock Code'));
      await tester.pump();

      expect(notifier.lastInitiateMobile, '+26771234567');
    });

    testWidgets('shows loading during initiation', (tester) async {
      final notifier = _LoadingNotifier();
      await tester.pumpWidget(_wrap(const PinUnblockScreen(), notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    // ── OTP entry phase ──────────────────────────────────────────────────────

    testWidgets('shows OTP entry when state is AuthPinUnblockPending',
        (tester) async {
      final notifier = _UnblockPendingNotifier();
      await tester.pumpWidget(_wrap(const PinUnblockScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Enter unlock code'), findsOneWidget);
      expect(find.text('Verify'), findsOneWidget);
    });

    testWidgets('calls completePinUnblock with challengeId from state',
        (tester) async {
      final notifier = _UnblockPendingNotifier();
      await tester.pumpWidget(_wrap(const PinUnblockScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '654321');
      await tester.pumpAndSettle();

      // Auto-submits on 6 digits (via onChanged) — verify was called.
      expect(notifier.lastCompleteChallengeId, 'ch-unblock-001');
      expect(notifier.lastCompleteOtp, '654321');
    });

    testWidgets('Verify button triggers completePinUnblock', (tester) async {
      final notifier = _UnblockPendingNotifier();
      await tester.pumpWidget(_wrap(const PinUnblockScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '123456');
      await tester.tap(find.text('Verify'));
      await tester.pump();

      expect(notifier.lastCompleteChallengeId, 'ch-unblock-001');
    });

    testWidgets('shows error message on OTP failure', (tester) async {
      final notifier = _UnblockErrorNotifier();
      await tester.pumpWidget(_wrap(const PinUnblockScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('OTP invalid or expired.'), findsOneWidget);
    });

    // ── Accessibility (T173) ─────────────────────────────────────────────────

    testWidgets('mobile entry heading is present', (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(_wrap(const PinUnblockScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Recover locked account'), findsOneWidget);
    });

    testWidgets('OTP input has accessibility label widget', (tester) async {
      final notifier = _UnblockPendingNotifier();
      await tester.pumpWidget(_wrap(const PinUnblockScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      // The Semantics widget with label 'Unlock code, 6 digits' is in the tree.
      expect(
        find.byWidgetPredicate(
          (w) => w is Semantics && w.properties.label == 'Unlock code, 6 digits',
        ),
        findsOneWidget,
      );
    });
  });
}
