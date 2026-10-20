import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/screens/device/device_verify_screen.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';

// ── Stub notifiers ────────────────────────────────────────────────────────────

class _DeviceVerifyNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthDeviceVerificationRequired(
        challengeId: 'ch-device-1',
      );

  String? verifiedChallengeId;
  String? verifiedOtp;

  @override
  Future<void> verifyDevice(String challengeId, String otp) async {
    verifiedChallengeId = challengeId;
    verifiedOtp = otp;
  }
}

class _ErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthDeviceVerificationRequired(
        challengeId: 'ch-device-1',
        errorCode: 'IDENTITY_003',
        errorMessage: 'Invalid device verification code.',
      );
}

class _LoadingNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() => Completer<AuthState>().future;
}

// ── Helper ────────────────────────────────────────────────────────────────────

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

// ── Tests ─────────────────────────────────────────────────────────────────────

void main() {
  group('DeviceVerifyScreen — widget tests (T221, T178)', () {
    testWidgets('renders heading, OTP field and verify button', (tester) async {
      await tester.pumpWidget(
          _wrap(const DeviceVerifyScreen(), notifier: _DeviceVerifyNotifier()));
      await tester.pumpAndSettle();

      expect(find.text('Verify Device'), findsWidgets); // AppBar + button
      expect(find.text('New device detected'), findsOneWidget);
      expect(find.text('Verification code'), findsOneWidget);
    });

    testWidgets('Verify Device button calls verifyDevice with correct args',
        (tester) async {
      final notifier = _DeviceVerifyNotifier();
      await tester.pumpWidget(
          _wrap(const DeviceVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '123456');
      await tester.tap(find.text('Verify Device').last);
      await tester.pump();

      expect(notifier.verifiedChallengeId, 'ch-device-1');
      expect(notifier.verifiedOtp, '123456');
    });

    testWidgets('auto-submits when 6th digit entered via onChanged',
        (tester) async {
      final notifier = _DeviceVerifyNotifier();
      await tester.pumpWidget(
          _wrap(const DeviceVerifyScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '654321');
      await tester.pump();

      expect(notifier.verifiedOtp, '654321');
    });

    testWidgets('shows error message from AuthDeviceVerificationRequired state',
        (tester) async {
      await tester.pumpWidget(
          _wrap(const DeviceVerifyScreen(), notifier: _ErrorNotifier()));
      await tester.pumpAndSettle();

      expect(find.text('Invalid device verification code.'), findsWidgets);
    });

    testWidgets('shows loading widget while request in flight', (tester) async {
      await tester.pumpWidget(
          _wrap(const DeviceVerifyScreen(), notifier: _LoadingNotifier()));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('heading has Semantics header=true', (tester) async {
      await tester.pumpWidget(
          _wrap(const DeviceVerifyScreen(), notifier: _DeviceVerifyNotifier()));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.header == true &&
              w.child is Text &&
              (w.child as Text).data == 'New device detected',
        ),
        findsOneWidget,
      );
    });

    testWidgets('OTP field has Semantics label for screen readers',
        (tester) async {
      await tester.pumpWidget(
          _wrap(const DeviceVerifyScreen(), notifier: _DeviceVerifyNotifier()));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              (w.properties.label?.contains('Device verification code') ??
                  false),
        ),
        findsWidgets,
      );
    });

    testWidgets('error text is Semantics liveRegion', (tester) async {
      await tester.pumpWidget(
          _wrap(const DeviceVerifyScreen(), notifier: _ErrorNotifier()));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.liveRegion == true &&
              w.child is Text &&
              (w.child as Text).data == 'Invalid device verification code.',
        ),
        findsOneWidget,
      );
    });
  });
}
