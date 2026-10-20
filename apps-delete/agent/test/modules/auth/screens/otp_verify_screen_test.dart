import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/screens/otp/otp_verify_screen.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';

class _OtpPendingNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => AuthOtpPending(
        challengeId: 'ch-001',
        expiresAt: DateTime.now().add(const Duration(minutes: 10)),
        maskedDestination: '+267***4567',
      );

  String? lastChallengeId;
  String? lastOtp;

  @override
  Future<void> verifyOtp(String challengeId, String otp) async {
    lastChallengeId = challengeId;
    lastOtp = otp;
  }

  @override
  Future<void> resendOtp(String challengeId) async {}
}

class _OtpErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => AuthOtpPending(
        challengeId: 'ch-001',
        expiresAt: DateTime.now().add(const Duration(minutes: 10)),
        errorCode: 'IDENTITY_003',
        errorMessage: 'OTP invalid or expired.',
      );
}

class _LoadingNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() => Completer<AuthState>().future;
}

Widget _wrap(Widget child, {required AuthStateNotifier notifier}) {
  return ProviderScope(
    overrides: [
      authStateNotifierProvider.overrideWith(() => notifier),
      authTokenStorageProvider.overrideWith((_) => throw UnimplementedError()),
      authApiServiceProvider.overrideWith((_) => throw UnimplementedError()),
    ],
    child: MaterialApp(
      theme: ThemeData(splashFactory: NoSplash.splashFactory),
      home: child,
    ),
  );
}

void main() {
  group('OtpVerifyScreen — widget tests (T176, T178)', () {
    testWidgets('shows 6-digit OTP input and verify button', (tester) async {
      final notifier = _OtpPendingNotifier();
      await tester.pumpWidget(
          _wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Enter the 6-digit code'), findsOneWidget);
      expect(find.text('Verify'), findsOneWidget);
      expect(find.text('Code sent to +267***4567'), findsOneWidget);
    });

    testWidgets('calls verifyOtp on Verify button tap', (tester) async {
      final notifier = _OtpPendingNotifier();
      await tester.pumpWidget(
          _wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Verify'));
      await tester.pump();

      expect(notifier.lastChallengeId, 'ch-001');
    });

    testWidgets('shows error message on OTP failure', (tester) async {
      final notifier = _OtpErrorNotifier();
      await tester.pumpWidget(
          _wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('OTP invalid or expired.'), findsOneWidget);
    });

    testWidgets('shows loading indicator while loading', (tester) async {
      final notifier = _LoadingNotifier();
      await tester.pumpWidget(
          _wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsWidgets);
    });

    testWidgets('OTP input has accessibility label', (tester) async {
      final notifier = _OtpPendingNotifier();
      await tester.pumpWidget(
          _wrap(const OtpVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.label == 'One-time code, 6 digits',
        ),
        findsOneWidget,
      );
    });
  });
}
