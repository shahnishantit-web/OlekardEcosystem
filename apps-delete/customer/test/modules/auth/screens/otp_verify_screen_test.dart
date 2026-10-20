import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer/modules/auth/state/auth_state.dart';
import 'package:customer/modules/auth/state/auth_state_notifier.dart';
import 'package:customer/modules/auth/screens/otp/otp_verify_screen.dart';
import 'package:customer/modules/auth/storage/auth_token_storage.dart';
import 'package:customer/modules/auth/services/auth_api_service.dart';

class _OtpPendingNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => AuthOtpPending(
        challengeId: 'ch-001',
        expiresAt: DateTime.now().add(const Duration(minutes: 5)),
        maskedDestination: '+267 *** *** 567',
        resendAt: DateTime.now().add(const Duration(seconds: 60)),
      );

  // Track verify calls.
  String? lastVerifiedChallenge;
  String? lastVerifiedOtp;

  @override
  Future<void> verifyOtp(String challengeId, String otp) async {
    lastVerifiedChallenge = challengeId;
    lastVerifiedOtp = otp;
  }
}

class _OtpErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => AuthOtpPending(
        challengeId: 'ch-001',
        expiresAt: DateTime.now().add(const Duration(minutes: 5)),
        errorCode: 'IDENTITY_003',
        errorMessage: 'OTP invalid or expired.',
      );
}

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

void main() {
  group('OtpVerifyScreen — widget tests (T171)', () {
    testWidgets('renders masked destination', (tester) async {
      final notifier = _OtpPendingNotifier();
      await tester.pumpWidget(_wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.textContaining('+267 *** *** 567'), findsOneWidget);
    });

    testWidgets('shows Verify button', (tester) async {
      final notifier = _OtpPendingNotifier();
      await tester.pumpWidget(_wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Verify'), findsOneWidget);
    });

    testWidgets('displays error message on OTP error state', (tester) async {
      final notifier = _OtpErrorNotifier();
      await tester.pumpWidget(_wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('OTP invalid or expired.'), findsOneWidget);
    });

    testWidgets('auto-submits when 6 digits are entered', (tester) async {
      final notifier = _OtpPendingNotifier();
      await tester.pumpWidget(_wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      // Type 6 digits into the hidden text field.
      await tester.enterText(find.byType(TextField).last, '123456');
      await tester.pump();

      expect(notifier.lastVerifiedOtp, '123456');
      expect(notifier.lastVerifiedChallenge, 'ch-001');
    });

    // ── Accessibility (T173) ─────────────────────────────────────────────────

    testWidgets('OTP input row has semantics label', (tester) async {
      final notifier = _OtpPendingNotifier();
      await tester.pumpWidget(_wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      // Semantics label is "One-time code, 6 digits".
      expect(
        find.bySemanticsLabel('One-time code, 6 digits'),
        findsOneWidget,
      );
    });

    testWidgets('resend button is initially disabled (cooldown active)',
        (tester) async {
      final notifier = _OtpPendingNotifier();
      await tester.pumpWidget(_wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      // Resend is the only TextButton on this screen. canResend=false while
      // resendAt is in the future, so onPressed must be null.
      final resendBtn = tester.widget<TextButton>(find.byType(TextButton));
      expect(resendBtn.onPressed, isNull);
    });
  });
}
