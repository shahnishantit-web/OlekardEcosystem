import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/screens/pin/pin_change_screen.dart';
import 'package:agent/modules/auth/screens/pin/pin_keypad.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';

// ── Stub notifiers ────────────────────────────────────────────────────────────

class _AuthedNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async =>
      const AuthAuthenticated(sessionId: 'ses-1');

  String? lastCurrentPin;
  String? lastNewPin;

  @override
  Future<void> changePin(String currentPin, String newPin) async {
    lastCurrentPin = currentPin;
    lastNewPin = newPin;
  }
}

class _ErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthUnauthenticated(
        errorCode: 'IDENTITY_002',
        errorMessage: 'Current PIN is incorrect.',
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
  group('PinChangeScreen — widget tests (T218, T178)', () {
    testWidgets('renders PinDots and PinKeypad for current PIN phase',
        (tester) async {
      await tester.pumpWidget(
          _wrap(const PinChangeScreen(), notifier: _AuthedNotifier()));
      await tester.pumpAndSettle();

      expect(find.byType(PinDots), findsOneWidget);
      expect(find.byType(PinKeypad), findsOneWidget);
      expect(find.text('Enter your current PIN'), findsWidgets);
    });

    testWidgets('advances through all three phases and calls changePin',
        (tester) async {
      final notifier = _AuthedNotifier();
      await tester
          .pumpWidget(_wrap(const PinChangeScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      // Current PIN
      for (final d in ['1', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }
      expect(find.text('Enter your new PIN'), findsWidgets);

      // New PIN
      for (final d in ['2', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }
      expect(find.text('Confirm your new PIN'), findsWidgets);

      // Confirm new PIN
      for (final d in ['2', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }

      expect(notifier.lastCurrentPin, '123456');
      expect(notifier.lastNewPin, '223456');
    });

    testWidgets('shows mismatch error when new PIN confirmation differs',
        (tester) async {
      await tester.binding.setSurfaceSize(const Size(800, 1200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final notifier = _AuthedNotifier();
      await tester
          .pumpWidget(_wrap(const PinChangeScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      // Current PIN
      for (final d in ['1', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }
      // New PIN
      for (final d in ['2', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }
      // Wrong confirm
      for (final d in ['9', '9', '9', '9', '9', '9']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }

      expect(find.text('PINs do not match. Please try again.'), findsOneWidget);
    });

    testWidgets('shows API error from AuthUnauthenticated state', (tester) async {
      await tester.binding.setSurfaceSize(const Size(800, 1200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
          _wrap(const PinChangeScreen(), notifier: _ErrorNotifier()));
      await tester.pumpAndSettle();

      expect(find.text('Current PIN is incorrect.'), findsOneWidget);
    });

    testWidgets('shows loading widget while request in flight', (tester) async {
      await tester.pumpWidget(
          _wrap(const PinChangeScreen(), notifier: _LoadingNotifier()));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('no biometric button in Agent PinKeypad (agent scope)',
        (tester) async {
      await tester.pumpWidget(
          _wrap(const PinChangeScreen(), notifier: _AuthedNotifier()));
      await tester.pumpAndSettle();

      expect(find.byIcon(Icons.fingerprint), findsNothing);
    });

    testWidgets('phase subtitle has Semantics header=true', (tester) async {
      await tester.pumpWidget(
          _wrap(const PinChangeScreen(), notifier: _AuthedNotifier()));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.header == true &&
              w.child is Text &&
              (w.child as Text).data == 'Enter your current PIN',
        ),
        findsOneWidget,
      );
    });
  });
}
