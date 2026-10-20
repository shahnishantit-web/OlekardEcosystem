import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/screens/pin/pin_create_screen.dart';
import 'package:agent/modules/auth/screens/pin/pin_keypad.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';

// ── Stub notifiers ────────────────────────────────────────────────────────────

class _PinPendingNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthPinPending(
        userId: 'usr-1',
        setupToken: 'tok-1',
      );

  String? lastPin;

  @override
  Future<void> setPin(String pin) async {
    lastPin = pin;
  }
}

class _LoadingNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() => Completer<AuthState>().future;
}

class _ErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthPinPending(
        userId: 'usr-1',
        setupToken: 'tok-1',
        errorCode: 'IDENTITY_031',
        errorMessage: 'PIN does not meet complexity requirements.',
      );
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
  group('PinCreateScreen — widget tests (T217, T178)', () {
    testWidgets('renders PinDots and PinKeypad in entry phase', (tester) async {
      await tester.pumpWidget(
          _wrap(const PinCreateScreen(), notifier: _PinPendingNotifier()));
      await tester.pumpAndSettle();

      expect(find.byType(PinDots), findsOneWidget);
      expect(find.byType(PinKeypad), findsOneWidget);
      expect(find.text('Create PIN'), findsWidgets);
    });

    testWidgets('advances to confirm phase after 6 digits', (tester) async {
      await tester.pumpWidget(
          _wrap(const PinCreateScreen(), notifier: _PinPendingNotifier()));
      await tester.pumpAndSettle();

      for (final d in ['1', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }

      expect(find.text('Confirm PIN'), findsWidgets);
    });

    testWidgets('shows mismatch error and resets when PINs differ',
        (tester) async {
      final notifier = _PinPendingNotifier();
      await tester
          .pumpWidget(_wrap(const PinCreateScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      for (final d in ['1', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }
      for (final d in ['6', '5', '4', '3', '2', '1']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }

      expect(find.text('PINs do not match. Please try again.'), findsOneWidget);
      expect(find.text('Create PIN'), findsWidgets);
    });

    testWidgets('calls setPin when confirmed PINs match', (tester) async {
      final notifier = _PinPendingNotifier();
      await tester
          .pumpWidget(_wrap(const PinCreateScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      for (final d in ['1', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }
      for (final d in ['1', '2', '3', '4', '5', '6']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }

      expect(notifier.lastPin, '123456');
    });

    testWidgets('shows API error from AuthPinPending state', (tester) async {
      await tester.binding.setSurfaceSize(const Size(800, 1200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
          _wrap(const PinCreateScreen(), notifier: _ErrorNotifier()));
      await tester.pumpAndSettle();

      expect(find.text('PIN does not meet complexity requirements.'),
          findsOneWidget);
    });

    testWidgets('shows loading widget while request in flight', (tester) async {
      await tester.pumpWidget(
          _wrap(const PinCreateScreen(), notifier: _LoadingNotifier()));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('no biometric button in Agent PinKeypad (agent scope)',
        (tester) async {
      await tester.pumpWidget(
          _wrap(const PinCreateScreen(), notifier: _PinPendingNotifier()));
      await tester.pumpAndSettle();

      expect(find.byIcon(Icons.fingerprint), findsNothing);
    });

    testWidgets('title has Semantics header=true', (tester) async {
      await tester.pumpWidget(
          _wrap(const PinCreateScreen(), notifier: _PinPendingNotifier()));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.header == true &&
              w.child is Text &&
              (w.child as Text).data == 'Create PIN',
        ),
        findsOneWidget,
      );
    });

    testWidgets('error text is Semantics liveRegion', (tester) async {
      await tester.binding.setSurfaceSize(const Size(800, 1200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
          _wrap(const PinCreateScreen(), notifier: _ErrorNotifier()));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.liveRegion == true &&
              w.child is Text &&
              (w.child as Text).data ==
                  'PIN does not meet complexity requirements.',
        ),
        findsOneWidget,
      );
    });
  });
}
