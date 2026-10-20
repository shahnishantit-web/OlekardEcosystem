import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/screens/pin/pin_reset_screen.dart';
import 'package:agent/modules/auth/screens/pin/pin_keypad.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';

// ── Stub notifiers ────────────────────────────────────────────────────────────

class _BaseNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthUnauthenticated();

  String? initiatedFor;

  @override
  Future<void> initiatePinReset(String mobileNumber) async {
    initiatedFor = mobileNumber;
    state = AsyncData(AuthOtpPending(
      challengeId: 'ch-1',
      expiresAt: DateTime.now().add(const Duration(minutes: 10)),
    ));
  }
}

class _ErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => AuthOtpPending(
        challengeId: 'ch-1',
        expiresAt: DateTime.now().add(const Duration(minutes: 10)),
        errorCode: 'IDENTITY_003',
        errorMessage: 'Invalid reset code.',
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
  group('PinResetScreen — widget tests (T219, T178)', () {
    testWidgets('renders mobile entry phase initially', (tester) async {
      await tester.pumpWidget(
          _wrap(const PinResetScreen(), notifier: _BaseNotifier()));
      await tester.pumpAndSettle();

      expect(find.text('Reset PIN'), findsOneWidget);
      expect(find.text('Mobile number'), findsOneWidget);
      expect(find.text('Send Reset Code'), findsOneWidget);
    });

    testWidgets('submitting mobile number calls initiatePinReset',
        (tester) async {
      final notifier = _BaseNotifier();
      await tester
          .pumpWidget(_wrap(const PinResetScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '+26771234567');
      await tester.tap(find.text('Send Reset Code'));
      await tester.pumpAndSettle();

      expect(notifier.initiatedFor, '+26771234567');
    });

    testWidgets('advances to OTP phase after successful initiation',
        (tester) async {
      final notifier = _BaseNotifier();
      await tester
          .pumpWidget(_wrap(const PinResetScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '+26771234567');
      await tester.tap(find.text('Send Reset Code'));
      await tester.pumpAndSettle();

      expect(find.text('Verification code'), findsOneWidget);
      expect(find.text('Continue'), findsOneWidget);
    });

    testWidgets('advances to PIN phases after OTP entry', (tester) async {
      final notifier = _BaseNotifier();
      await tester
          .pumpWidget(_wrap(const PinResetScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '+26771234567');
      await tester.tap(find.text('Send Reset Code'));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '123456');
      await tester.tap(find.text('Continue'));
      await tester.pumpAndSettle();

      expect(find.byType(PinDots), findsOneWidget);
      expect(find.byType(PinKeypad), findsOneWidget);
      expect(find.text('Enter new PIN'), findsWidgets);
    });

    testWidgets('shows PIN mismatch error in confirm phase', (tester) async {
      await tester.binding.setSurfaceSize(const Size(800, 1200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final notifier = _BaseNotifier();
      await tester
          .pumpWidget(_wrap(const PinResetScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '+26771234567');
      await tester.tap(find.text('Send Reset Code'));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '123456');
      await tester.tap(find.text('Continue'));
      await tester.pumpAndSettle();

      // Enter new PIN
      for (final d in ['1', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }
      // Wrong confirm PIN
      for (final d in ['9', '9', '9', '9', '9', '9']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }

      expect(find.text('PINs do not match. Try again.'), findsOneWidget);
    });

    testWidgets('shows API error from OTP state', (tester) async {
      await tester.pumpWidget(
          _wrap(const PinResetScreen(), notifier: _ErrorNotifier()));
      await tester.pumpAndSettle();

      expect(find.text('Invalid reset code.'), findsOneWidget);
    });

    testWidgets('shows loading widget while request in flight', (tester) async {
      await tester.pumpWidget(
          _wrap(const PinResetScreen(), notifier: _LoadingNotifier()));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('PIN phase title has Semantics header=true', (tester) async {
      await tester.binding.setSurfaceSize(const Size(800, 1200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final notifier = _BaseNotifier();
      await tester
          .pumpWidget(_wrap(const PinResetScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '+26771234567');
      await tester.tap(find.text('Send Reset Code'));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '123456');
      await tester.tap(find.text('Continue'));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.header == true &&
              w.child is Text &&
              (w.child as Text).data == 'Enter new PIN',
        ),
        findsOneWidget,
      );
    });
  });
}
