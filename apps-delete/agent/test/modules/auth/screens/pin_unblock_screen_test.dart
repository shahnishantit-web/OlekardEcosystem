import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/screens/pin/pin_unblock_screen.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';

// ── Stub notifiers ────────────────────────────────────────────────────────────

class _LockedNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => const AuthLocked();

  String? unblockInitiatedFor;

  @override
  Future<void> initiatePinUnblock(String mobileNumber) async {
    unblockInitiatedFor = mobileNumber;
    state = AsyncData(AuthPinUnblockPending(
      challengeId: 'ch-1',
      expiresAt: DateTime.now().add(const Duration(minutes: 10)),
    ));
  }
}

class _UnblockPendingNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => AuthPinUnblockPending(
        challengeId: 'ch-1',
        expiresAt: DateTime.now().add(const Duration(minutes: 10)),
      );
}

class _ErrorNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async => AuthPinUnblockPending(
        challengeId: 'ch-1',
        expiresAt: DateTime.now().add(const Duration(minutes: 10)),
        errorCode: 'IDENTITY_003',
        errorMessage: 'Invalid unlock code.',
      );
}

class _LoadingNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() => Completer<AuthState>().future;
}

// ── Helper ────────────────────────────────────────────────────────────────────

Widget _wrapWithRouter(AuthStateNotifier notifier) {
  final router = GoRouter(
    routes: [
      GoRoute(
        path: '/',
        builder: (_, __) => ProviderScope(
          overrides: [
            authStateNotifierProvider.overrideWith(() => notifier),
            authTokenStorageProvider
                .overrideWith((_) => throw UnimplementedError()),
            authApiServiceProvider
                .overrideWith((_) => throw UnimplementedError()),
          ],
          child: const PinUnblockScreen(),
        ),
      ),
      GoRoute(path: '/auth/register', builder: (_, __) => const Scaffold()),
    ],
  );
  return MaterialApp.router(
    theme: ThemeData(splashFactory: NoSplash.splashFactory),
    routerConfig: router,
  );
}

Widget _wrap(AuthStateNotifier notifier) {
  return ProviderScope(
    overrides: [
      authStateNotifierProvider.overrideWith(() => notifier),
      authTokenStorageProvider.overrideWith((_) => throw UnimplementedError()),
      authApiServiceProvider.overrideWith((_) => throw UnimplementedError()),
    ],
    child: MaterialApp(
      theme: ThemeData(splashFactory: NoSplash.splashFactory),
      home: const PinUnblockScreen(),
    ),
  );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

void main() {
  group('PinUnblockScreen — widget tests (T220, T178)', () {
    testWidgets('shows mobile entry phase when not yet in OTP phase',
        (tester) async {
      await tester.pumpWidget(_wrap(_LockedNotifier()));
      await tester.pumpAndSettle();

      expect(find.text('Recover Account'), findsOneWidget);
      expect(find.text('Recover locked account'), findsOneWidget);
      expect(find.text('Mobile number'), findsOneWidget);
      expect(find.text('Send Unlock Code'), findsOneWidget);
    });

    testWidgets('calling initiate with mobile number transitions to OTP phase',
        (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(_wrap(notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '+26771234567');
      await tester.tap(find.text('Send Unlock Code'));
      await tester.pumpAndSettle();

      expect(notifier.unblockInitiatedFor, '+26771234567');
    });

    testWidgets('shows OTP entry phase when state is AuthPinUnblockPending',
        (tester) async {
      await tester.pumpWidget(_wrap(_UnblockPendingNotifier()));
      await tester.pumpAndSettle();

      expect(find.text('Enter unlock code'), findsOneWidget);
      expect(find.text('Unlock code'), findsOneWidget);
      expect(find.text('Verify'), findsOneWidget);
    });

    testWidgets('shows error message in OTP phase', (tester) async {
      await tester.pumpWidget(_wrap(_ErrorNotifier()));
      await tester.pumpAndSettle();

      expect(find.text('Invalid unlock code.'), findsOneWidget);
    });

    testWidgets('shows loading widget while request in flight', (tester) async {
      await tester.pumpWidget(_wrap(_LoadingNotifier()));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('mobile entry heading has Semantics header=true',
        (tester) async {
      await tester.pumpWidget(_wrap(_LockedNotifier()));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.header == true &&
              w.child is Text &&
              (w.child as Text).data == 'Recover locked account',
        ),
        findsOneWidget,
      );
    });

    testWidgets('OTP entry heading has Semantics header=true', (tester) async {
      await tester.pumpWidget(_wrap(_UnblockPendingNotifier()));
      await tester.pumpAndSettle();

      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.header == true &&
              w.child is Text &&
              (w.child as Text).data == 'Enter unlock code',
        ),
        findsOneWidget,
      );
    });

    testWidgets('success view shows check_circle_outline icon',
        (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(_wrapWithRouter(notifier));
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), '+26771234567');
      await tester.tap(find.text('Send Unlock Code'));
      await tester.pumpAndSettle();

      // Simulate successful unblock — triggers _completed flag + 2s nav timer.
      notifier.state = const AsyncData(AuthUnauthenticated());
      await tester.pump(); // rebuild shows success view

      expect(find.byIcon(Icons.check_circle_outline), findsOneWidget);
      expect(find.text('Account Unlocked'), findsOneWidget);

      // Advance past the 2-second navigation timer so no pending timers remain.
      await tester.pump(const Duration(seconds: 3));
    });
  });
}
