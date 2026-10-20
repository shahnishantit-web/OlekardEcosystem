import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/screens/locked/account_locked_screen.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';
import 'package:go_router/go_router.dart';

class _LockedNotifier extends AuthStateNotifier {
  @override
  Future<AuthState> build() async =>
      const AuthLocked(errorMessage: 'Too many failed attempts.');

  bool logoutCalled = false;

  @override
  Future<void> logout() async {
    logoutCalled = true;
  }
}

Widget _wrap(Widget child, {required AuthStateNotifier notifier}) {
  return ProviderScope(
    overrides: [
      authStateNotifierProvider.overrideWith(() => notifier),
      authTokenStorageProvider.overrideWith((_) => throw UnimplementedError()),
      authApiServiceProvider.overrideWith((_) => throw UnimplementedError()),
    ],
    child: MaterialApp.router(
      theme: ThemeData(splashFactory: NoSplash.splashFactory),
      routerConfig: GoRouter(
        initialLocation: '/auth/locked',
        routes: [
          GoRoute(
            path: '/auth/locked',
            builder: (_, __) => child,
          ),
          GoRoute(
            path: '/auth/pin/unblock',
            builder: (_, __) =>
                const Scaffold(body: Text('Unblock screen')),
          ),
        ],
      ),
    ),
  );
}

void main() {
  group('AccountLockedScreen — widget tests (T176, T178)', () {
    testWidgets('shows locked message and CTA buttons', (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(
          _wrap(const AccountLockedScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.text('Account Locked'), findsOneWidget);
      expect(find.text('Too many failed attempts.'), findsOneWidget);
      expect(find.text('Recover via SMS'), findsOneWidget);
      expect(find.text('Sign out'), findsOneWidget);
    });

    testWidgets('Recover via SMS navigates to /auth/pin/unblock',
        (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(
          _wrap(const AccountLockedScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Recover via SMS'));
      await tester.pumpAndSettle();

      expect(find.text('Unblock screen'), findsOneWidget);
    });

    testWidgets('Sign out calls logout on notifier', (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(
          _wrap(const AccountLockedScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Sign out'));
      await tester.pump();

      expect(notifier.logoutCalled, isTrue);
    });

    testWidgets('lock icon has semantic label', (tester) async {
      final notifier = _LockedNotifier();
      await tester.pumpWidget(
          _wrap(const AccountLockedScreen(), notifier: notifier));
      await tester.pumpAndSettle();

      expect(find.byIcon(Icons.lock_outline), findsOneWidget);
    });
  });
}
